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

    let socket = null;
    let micStream = null, audioCtx = null, scriptNode = null, gainNode = null;
    let audioQueue = [], isPlayingAudio = false;
    let handlers = {}, cfg = {};

    function playNext() {
        if (isPlayingAudio || audioQueue.length === 0) return;
        isPlayingAudio = true;
        const pcm16 = audioQueue.shift();
        const ctx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 24000 });
        const buf = ctx.createBuffer(1, pcm16.length, 24000);
        const ch = buf.getChannelData(0);
        for (let i = 0; i < pcm16.length; i++) ch[i] = pcm16[i] / 32768.0;
        const src = ctx.createBufferSource();
        src.buffer = buf;
        src.connect(ctx.destination);
        src.onended = () => {
            ctx.close().catch(() => {});
            isPlayingAudio = false;
            if (audioQueue.length > 0) playNext();
            else speaking.value = false;
        };
        src.start(0);
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
            if (!socket || !socket.connected) return;
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
            const pcm16 = new Int16Array(pcmFloat.length);
            for (let i = 0; i < pcmFloat.length; i++) {
                const s = Math.max(-1, Math.min(1, pcmFloat[i]));
                pcm16[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
            }
            socket.emit('audio', JSON.stringify({ audio: Array.from(new Uint8Array(pcm16.buffer)), sample_rate: 16000 }));
            // 輸出靜音，避免回授
            const out = e.outputBuffer.getChannelData(0);
            for (let i = 0; i < out.length; i++) out[i] = 0;
        };

        status.value = '請說話';
        socket.emit('prompt_text', { mode: cfg.mode || 'hybrid', conversation_id: cfg.conversationId ?? null });
        socket.emit('recording-started');
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
            const pcm = new Int16Array(data);
            if (!pcm.length) return;
            audioQueue.push(pcm);
            speaking.value = true;
            playNext();
        });
        socket.on('stop_tts', () => { audioQueue = []; speaking.value = false; });
        socket.on('ai_text', (t) => handlers.onAiText && handlers.onAiText(String(t || '')));
        socket.on('user_transcript', (t) => handlers.onTranscript && handlers.onTranscript(String(t || '')));
        socket.on('agent_step', (s) => handlers.onStep && handlers.onStep(String(s || '')));
    }

    function stop() {
        active.value = false;
        connected.value = false;
        speaking.value = false;
        status.value = '';
        try { socket && socket.connected && socket.emit('recording-stopped'); } catch (e) { /* noop */ }
        try { scriptNode && (scriptNode.onaudioprocess = null, scriptNode.disconnect()); } catch (e) { /* noop */ }
        try { gainNode && gainNode.disconnect(); } catch (e) { /* noop */ }
        try { micStream && micStream.getTracks().forEach((t) => t.stop()); } catch (e) { /* noop */ }
        try { audioCtx && audioCtx.close(); } catch (e) { /* noop */ }
        try { socket && socket.disconnect(); } catch (e) { /* noop */ }
        audioQueue = []; isPlayingAudio = false;
        socket = micStream = audioCtx = scriptNode = gainNode = null;
    }

    return { active, connected, speaking, status, start, stop };
}
