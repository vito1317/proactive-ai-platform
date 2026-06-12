import { ref } from 'vue';
import { io } from 'socket.io-client';

/**
 * 全雙工語音（瀏覽器 ↔ voice_server :8891 Socket.IO）。
 * 寫法對齊 intellitrust-website 的 VoiceCallWidget（已驗證可用）：
 *   - 擷取：AudioContext(16k) + Gain 2.0 + ScriptProcessor(2048,1,1) → emit audio(JSON)
 *   - 播放：每段 PCM16(24k) 進佇列、逐段以 AudioContext 播
 *   - 送：prompt_text{mode,conversation_id} · recording-started · audio
 *   - 收：audio · ai_text · user_transcript · agent_step · stop_tts
 */
export function useVoiceChat() {
    const active = ref(false);
    const connected = ref(false);
    const speaking = ref(false);
    const status = ref('');
    const volume = ref(0); // 0.0 to ~1.0
    const isMuted = ref(false);
    const liveVision = ref('off'); // off / screen / camera —— 即時把畫面給 AI 看

    let socket = null;
    let visionStream = null, visionVideo = null, visionTimer = null;
    let micStream = null, audioCtx = null, scriptNode = null, gainNode = null;
    let handlers = {}, cfg = {};
    let micMuteUntil = 0; // AI 播放期間暫停麥克風（純時間判斷，自動過期）
    let playCtx = null, playHead = 0, speakingTimer = null;
    let bargeIn = true;        // 允許在 AI 念回覆時插話打斷
    const BARGE_RMS = 0.06;    // 打斷門檻：麥克風音量超過此值才視為真人插話（過濾回授）

    function toggleMute() {
        isMuted.value = !isMuted.value;
        if (micStream) micStream.getAudioTracks().forEach((t) => { t.enabled = !isMuted.value; });
    }

    function ensurePlayCtx() {
        if (!playCtx || playCtx.state === 'closed') {
            playCtx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 24000 });
            playHead = 0;
        }
        if (playCtx.state === 'suspended') playCtx.resume();
        return playCtx;
    }

    // 無縫排程播放：把每段 PCM16(24k) 接在播放時鐘上，不靠 onended（避免網路抖動造成空隙/斷句）
    function enqueuePcm(pcm16) {
        if (!pcm16 || !pcm16.length) return;
        const ctx = ensurePlayCtx();
        const f32 = new Float32Array(pcm16.length);
        for (let i = 0; i < pcm16.length; i++) f32[i] = pcm16[i] / 32768.0;
        const buf = ctx.createBuffer(1, f32.length, 24000);
        buf.getChannelData(0).set(f32);
        const startAt = Math.max(ctx.currentTime + 0.08, playHead);
        const src = ctx.createBufferSource();
        src.buffer = buf;
        src.connect(ctx.destination);
        src.start(startAt);
        playHead = startAt + buf.duration;
        speaking.value = true;
        const remainMs = (playHead - ctx.currentTime) * 1000;
        micMuteUntil = performance.now() + remainMs + 600; // 播完 + 緩衝才開麥克風（防回授）
        clearTimeout(speakingTimer);
        speakingTimer = setTimeout(() => { speaking.value = false; }, remainMs + 200);
    }

    function stopPlayback() {
        clearTimeout(speakingTimer);
        speaking.value = false;
        playHead = 0;
        if (playCtx) { try { playCtx.close(); } catch (e) { /* noop */ } playCtx = null; }
    }

    async function startListening() {
        micStream = await navigator.mediaDevices.getUserMedia({
            audio: { channelCount: 1, echoCancellation: true, noiseSuppression: false, autoGainControl: false },
        });
        audioCtx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 16000 });
        if (audioCtx.state === 'suspended') await audioCtx.resume();
        const source = audioCtx.createMediaStreamSource(micStream);
        // 放大麥克風，確保伺服器 VAD 能偵測到語音（過小聲會斷不了句 → 沒反應）
        gainNode = audioCtx.createGain();
        gainNode.gain.value = 2.0;
        source.connect(gainNode);
        scriptNode = audioCtx.createScriptProcessor(2048, 1, 1);
        gainNode.connect(scriptNode);
        scriptNode.connect(audioCtx.destination);
        const nativeSR = audioCtx.sampleRate;

        scriptNode.onaudioprocess = (e) => {
            const out0 = e.outputBuffer.getChannelData(0);
            for (let i = 0; i < out0.length; i++) out0[i] = 0; // 永遠輸出靜音
            if (!socket || !socket.connected) return;
            if (isMuted.value) { volume.value = 0; return; }

            const input = e.inputBuffer.getChannelData(0);
            let pcmFloat;
            if (nativeSR !== 16000) {
                const ratio = nativeSR / 16000;
                const len = Math.round(input.length / ratio);
                pcmFloat = new Float32Array(len);
                for (let i = 0; i < len; i++) pcmFloat[i] = input[Math.round(i * ratio)];
            } else {
                pcmFloat = new Float32Array(input);
            }
            let sum = 0;
            const pcm16 = new Int16Array(pcmFloat.length);
            for (let i = 0; i < pcmFloat.length; i++) {
                sum += pcmFloat[i] * pcmFloat[i];
                const s = Math.max(-1, Math.min(1, pcmFloat[i]));
                pcm16[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
            }
            const rms = Math.sqrt(sum / pcmFloat.length);
            volume.value = rms;

            // AI 播放期間：預設可打斷（barge-in）——只有「明顯人聲」（音量高於自播回授）才送出，
            // 觸發後端中斷正在念的回覆；低音量回授丟棄，避免 AI 自我打斷。關閉打斷則完全靜音。
            const inPlayback = performance.now() < micMuteUntil;
            if (inPlayback) {
                if (!bargeIn || rms < BARGE_RMS) return;
            }

            socket.emit('audio', JSON.stringify({ audio: Array.from(new Uint8Array(pcm16.buffer)), sample_rate: 16000 }));
        };

        status.value = '請說話';
        socket.emit('prompt_text', promptPayload());
        socket.emit('recording-started');
        acquireGeo();
    }

    function promptPayload() {
        return { mode: cfg.mode || 'hybrid', conversation_id: cfg.conversationId ?? null, prompt: cfg.prompt || '', session: cfg.session || '', wake: !!cfg.wake, geo: cfg.geo || null, vision: liveVision.value !== 'off' };
    }

    // 取得定位（使用者允許才有）；拿到後補送 prompt_text 讓伺服器更新位置
    function acquireGeo() {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                cfg.geo = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                if (socket && socket.connected) socket.emit('prompt_text', promptPayload());
            },
            () => { /* 使用者拒絕定位 → 略過，附近搜尋會提示開權限 */ },
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 600000 },
        );
    }

    // 語音喚醒（Hey Siri 式）即時開關：重送 prompt_text 更新伺服器端 wake 旗標
    function setWake(enabled) {
        cfg.wake = !!enabled;
        if (socket && socket.connected) socket.emit('prompt_text', promptPayload());
    }

    function setBargeIn(enabled) { bargeIn = !!enabled; }

    async function start(c = {}, cbs = {}) {
        if (active.value) return;
        cfg = c; handlers = cbs;
        if (c.bargeIn !== undefined) bargeIn = !!c.bargeIn;
        active.value = true;
        status.value = '連線中…';

        socket = io(cfg.url || window.location.origin, {
            path: cfg.path || '/voice-rt/socket.io',
            transports: ['polling', 'websocket'],
            reconnectionAttempts: 5,
            timeout: 12000,
        });

        socket.on('connect', async () => {
            connected.value = true;
            status.value = '已連線 · 請說話';
            try {
                await startListening();
            } catch (e) {
                status.value = '無法取得麥克風權限';
                handlers.onError && handlers.onError('mic-denied');
            }
        });
        socket.on('disconnect', () => { connected.value = false; status.value = '已斷線'; });
        socket.on('connect_error', () => { status.value = '連線失敗'; });
        socket.on('too_many_users', () => { status.value = '語音通道已滿，請稍後'; stop(); });
        socket.on('audio', (data) => {
            const buf = data instanceof ArrayBuffer ? data : (data?.buffer ?? data);
            enqueuePcm(new Int16Array(buf));
        });
        socket.on('stop_tts', () => stopPlayback());
        socket.on('ai_text', (t) => handlers.onAiText && handlers.onAiText(String(t || '')));
        socket.on('user_transcript', (t) => handlers.onTranscript && handlers.onTranscript(String(t || '')));
        socket.on('agent_step', (s) => handlers.onStep && handlers.onStep(String(s || '')));
    }

    // ── 即時畫面：把螢幕/鏡頭每 ~2 秒擷取一張給 AI（短 TTL，停止後自動恢復普通對話）──
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    async function pushFrame() {
        if (!visionVideo || visionVideo.videoWidth === 0) return;
        try {
            const w = Math.min(1280, visionVideo.videoWidth);
            const scale = w / visionVideo.videoWidth;
            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = visionVideo.videoHeight * scale;
            canvas.getContext('2d').drawImage(visionVideo, 0, 0, canvas.width, canvas.height);
            const image = canvas.toDataURL('image/jpeg', 0.7);
            await fetch('/api/vision/attach', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ image, session: cfg.session || '', ttl: 8 }),
            });
        } catch (e) { /* 單張失敗略過，下一張再來 */ }
    }
    async function setLiveVision(mode) {
        // mode: 'off' | 'screen' | 'camera'
        stopVision();
        if (mode === 'off' || !mode) {
            liveVision.value = 'off';
            if (socket && socket.connected) socket.emit('prompt_text', promptPayload());  // 通知 voice_server vision 關
            return;
        }
        try {
            visionStream = mode === 'screen'
                ? await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 1 }, audio: false })
                : await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            visionVideo = document.createElement('video');
            visionVideo.srcObject = visionStream; visionVideo.muted = true;
            await visionVideo.play();
            // 使用者自己關閉分享 → 自動回 off
            visionStream.getVideoTracks()[0].addEventListener('ended', () => setLiveVision('off'));
            liveVision.value = mode;
            if (socket && socket.connected) socket.emit('prompt_text', promptPayload());  // 通知 voice_server vision 開
            visionTimer = setInterval(pushFrame, 2000);
            pushFrame();
        } catch (e) {
            liveVision.value = 'off';
            status.value = mode === 'screen' ? '未允許螢幕分享' : '未允許使用鏡頭';
        }
    }
    function stopVision() {
        if (visionTimer) { clearInterval(visionTimer); visionTimer = null; }
        try { visionStream && visionStream.getTracks().forEach((t) => t.stop()); } catch (e) { /* noop */ }
        visionStream = visionVideo = null;
        liveVision.value = 'off';
    }

    function stop() {
        active.value = false;
        connected.value = false;
        speaking.value = false;
        status.value = '';
        volume.value = 0;
        isMuted.value = false;
        stopVision();
        try { socket && socket.connected && socket.emit('recording-stopped'); } catch (e) { /* noop */ }
        try { scriptNode && (scriptNode.onaudioprocess = null, scriptNode.disconnect()); } catch (e) { /* noop */ }
        try { gainNode && gainNode.disconnect(); } catch (e) { /* noop */ }
        try { micStream && micStream.getTracks().forEach((t) => t.stop()); } catch (e) { /* noop */ }
        try { audioCtx && audioCtx.close(); } catch (e) { /* noop */ }
        stopPlayback();
        try { socket && socket.disconnect(); } catch (e) { /* noop */ }
        socket = micStream = audioCtx = scriptNode = gainNode = null;
    }

    return { active, connected, speaking, status, volume, isMuted, liveVision, setLiveVision, toggleMute, setWake, setBargeIn, start, stop };
}
