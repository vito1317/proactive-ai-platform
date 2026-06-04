import { ref } from 'vue';
import { io } from 'socket.io-client';

/**
 * 全雙工語音（瀏覽器 ↔ voice_server :8891 Socket.IO）。
 * 協定（與電話 bridge 相同）：
 *   送：prompt_text{mode,conversation_id} · audio(JSON{audio:[…uint8],sample_rate:16000}) · recording-started/stopped
 *   收：audio(PCM16 24kHz bytes) · ai_text · user_transcript · agent_step · stop_tts
 * 麥克風 48k→16k 降採樣後逐段送；回傳音訊 24k 佇列播放；說話時送 recording-started 打斷 TTS。
 */
export function useVoiceChat() {
    const active = ref(false);     // 語音模式開啟中
    const connected = ref(false);  // socket 已連
    const speaking = ref(false);   // AI 正在說（播放中）
    const status = ref('');        // 狀態文字

    let socket = null;
    let micCtx = null, micStream = null, micNode = null, micSource = null;
    let playCtx = null, playQueue = [], playHead = 0, playing = false;
    let handlers = {};

    const IN_SR = 16000, OUT_SR = 24000;

    function emit(name, payload) { socket && socket.connected && socket.emit(name, payload); }

    // ── 播放：把收到的 PCM16(24k) 接成連續音流 ──
    function enqueuePcm(arrayBuf) {
        if (!playCtx) return;
        const i16 = new Int16Array(arrayBuf);
        if (!i16.length) return;
        const f32 = new Float32Array(i16.length);
        for (let i = 0; i < i16.length; i++) f32[i] = i16[i] / 32768;
        const buf = playCtx.createBuffer(1, f32.length, OUT_SR);
        buf.getChannelData(0).set(f32);
        const t = Math.max(playCtx.currentTime, playHead);
        const src = playCtx.createBufferSource();
        src.buffer = buf;
        src.connect(playCtx.destination);
        src.start(t);
        playHead = t + buf.duration;
        playQueue.push(src);
        speaking.value = true;
        src.onended = () => {
            playQueue = playQueue.filter((s) => s !== src);
            if (!playQueue.length) speaking.value = false;
        };
    }

    function stopPlayback() {
        playQueue.forEach((s) => { try { s.stop(); } catch (e) { /* noop */ } });
        playQueue = [];
        playHead = playCtx ? playCtx.currentTime : 0;
        speaking.value = false;
    }

    // ── 擷取麥克風：48k → 16k → int16 → 逐段送 ──
    function startMic() {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        micCtx = new Ctx();
        micSource = micCtx.createMediaStreamSource(micStream);
        const bufSize = 4096;
        micNode = micCtx.createScriptProcessor(bufSize, 1, 0);
        const ratio = micCtx.sampleRate / IN_SR;
        let wasSpeaking = false;
        micNode.onaudioprocess = (e) => {
            const input = e.inputBuffer.getChannelData(0);
            // 簡易降採樣（取樣 + 平均）到 16kHz
            const outLen = Math.floor(input.length / ratio);
            const i16 = new Int16Array(outLen);
            for (let i = 0; i < outLen; i++) {
                const start = Math.floor(i * ratio);
                let s = input[start] || 0;
                s = Math.max(-1, Math.min(1, s));
                i16[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
            }
            // 偵測本地語音能量 → 打斷 AI（barge-in）
            if (speaking.value) {
                let energy = 0;
                for (let i = 0; i < outLen; i++) energy += Math.abs(i16[i]);
                if (energy / outLen > 600 && !wasSpeaking) {
                    wasSpeaking = true;
                    emit('recording-started');
                    stopPlayback();
                } else if (energy / outLen <= 400) {
                    wasSpeaking = false;
                }
            }
            const bytes = new Uint8Array(i16.buffer);
            emit('audio', JSON.stringify({ audio: Array.from(bytes), sample_rate: IN_SR }));
        };
        micSource.connect(micNode);
        micNode.connect(micCtx.destination); // ScriptProcessor 需連到 destination 才會觸發
    }

    async function start({ url, path, mode, conversationId } = {}, cbs = {}) {
        if (active.value) return;
        handlers = cbs;
        status.value = '取得麥克風…';
        try {
            micStream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
        } catch (e) {
            status.value = '無法取得麥克風權限';
            handlers.onError && handlers.onError('mic-denied');
            return;
        }

        playCtx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: OUT_SR });
        active.value = true;
        status.value = '連線中…';

        socket = io(url || window.location.origin, {
            path: path || '/voice-rt/socket.io',
            transports: ['websocket', 'polling'],
            reconnection: false,
        });

        socket.on('connect', () => {
            connected.value = true;
            status.value = '已連線 · 請說話';
            socket.emit('prompt_text', { mode: mode || 'hybrid', conversation_id: conversationId ?? null });
            startMic();
        });
        socket.on('disconnect', () => { connected.value = false; status.value = '已斷線'; });
        socket.on('too_many_users', () => { status.value = '語音通道已滿，請稍後'; stop(); });
        socket.on('audio', (data) => {
            const buf = data instanceof ArrayBuffer ? data : (data?.buffer ?? null);
            if (buf) enqueuePcm(buf);
        });
        socket.on('stop_tts', () => stopPlayback());
        socket.on('user_transcript', (t) => handlers.onTranscript && handlers.onTranscript(String(t || '')));
        socket.on('ai_text', (t) => handlers.onAiText && handlers.onAiText(String(t || '')));
        socket.on('agent_step', (s) => handlers.onStep && handlers.onStep(String(s || '')));
        socket.on('connect_error', (e) => { status.value = '連線失敗'; handlers.onError && handlers.onError('connect'); });
    }

    function stop() {
        active.value = false;
        connected.value = false;
        speaking.value = false;
        status.value = '';
        try { emit('recording-stopped'); } catch (e) { /* noop */ }
        try { micNode && (micNode.onaudioprocess = null, micNode.disconnect()); } catch (e) { /* noop */ }
        try { micSource && micSource.disconnect(); } catch (e) { /* noop */ }
        try { micStream && micStream.getTracks().forEach((t) => t.stop()); } catch (e) { /* noop */ }
        try { micCtx && micCtx.close(); } catch (e) { /* noop */ }
        stopPlayback();
        try { playCtx && playCtx.close(); } catch (e) { /* noop */ }
        try { socket && socket.disconnect(); } catch (e) { /* noop */ }
        socket = micCtx = micStream = micNode = micSource = playCtx = null;
    }

    return { active, connected, speaking, status, start, stop };
}
