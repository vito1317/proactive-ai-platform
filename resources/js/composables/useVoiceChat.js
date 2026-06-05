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

    let socket = null;
    let micStream = null, audioCtx = null, scriptNode = null, gainNode = null;
    let handlers = {}, cfg = {};
    let micMuteUntil = 0; // AI 播放期間暫停麥克風（純時間判斷，自動過期）
    let playCtx = null, playHead = 0, speakingTimer = null;

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
            // AI 播放期間（+尾音緩衝，純時間判斷會自動過期）/ 手動靜音 → 不送麥克風（防回授）
            if (isMuted.value || performance.now() < micMuteUntil) {
                volume.value = 0;
                return;
            }
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
            volume.value = Math.sqrt(sum / pcmFloat.length);

            socket.emit('audio', JSON.stringify({ audio: Array.from(new Uint8Array(pcm16.buffer)), sample_rate: 16000 }));
            // 輸出靜音，避免回授
            const out = e.outputBuffer.getChannelData(0);
            for (let i = 0; i < out.length; i++) out[i] = 0;
        };

        status.value = '請說話';
        socket.emit('prompt_text', promptPayload());
        socket.emit('recording-started');
    }

    function promptPayload() {
        return { mode: cfg.mode || 'hybrid', conversation_id: cfg.conversationId ?? null, prompt: cfg.prompt || '', session: cfg.session || '', wake: !!cfg.wake };
    }

    // 語音喚醒（Hey Siri 式）即時開關：重送 prompt_text 更新伺服器端 wake 旗標
    function setWake(enabled) {
        cfg.wake = !!enabled;
        if (socket && socket.connected) socket.emit('prompt_text', promptPayload());
    }

    async function start(c = {}, cbs = {}) {
        if (active.value) return;
        cfg = c; handlers = cbs;
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

    function stop() {
        active.value = false;
        connected.value = false;
        speaking.value = false;
        status.value = '';
        volume.value = 0;
        isMuted.value = false;
        try { socket && socket.connected && socket.emit('recording-stopped'); } catch (e) { /* noop */ }
        try { scriptNode && (scriptNode.onaudioprocess = null, scriptNode.disconnect()); } catch (e) { /* noop */ }
        try { gainNode && gainNode.disconnect(); } catch (e) { /* noop */ }
        try { micStream && micStream.getTracks().forEach((t) => t.stop()); } catch (e) { /* noop */ }
        try { audioCtx && audioCtx.close(); } catch (e) { /* noop */ }
        stopPlayback();
        try { socket && socket.disconnect(); } catch (e) { /* noop */ }
        socket = micStream = audioCtx = scriptNode = gainNode = null;
    }

    return { active, connected, speaking, status, volume, isMuted, toggleMute, setWake, start, stop };
}
