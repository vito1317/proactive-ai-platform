<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, computed, watch, nextTick } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import { useVoiceChat } from '@/composables/useVoiceChat';
import UiIcon from '../Components/UiIcon.vue';

marked.setOptions({ gfm: true, breaks: true });
// LLM 偶爾輸出 LaTeX（如 $\rightarrow$、$\times$）→ 轉成 unicode；保留貨幣 $（後接數字的不動）
function delatex(s) {
    return String(s)
        .replace(/\\(?:long)?(?:right)?arrow\b/g, '→')
        .replace(/\\to\b/g, '→')
        .replace(/\\(?:long)?leftarrow\b/g, '←').replace(/\\gets\b/g, '←')
        .replace(/\\leftrightarrow\b/g, '↔')
        .replace(/\\times\b/g, '×').replace(/\\div\b/g, '÷').replace(/\\pm\b/g, '±')
        .replace(/\\leq?\b/g, '≤').replace(/\\geq?\b/g, '≥').replace(/\\neq\b/g, '≠')
        .replace(/\\approx\b/g, '≈').replace(/\\cdot\b/g, '·')
        .replace(/\\text\{([^}]*)\}/g, '$1')
        .replace(/\\\\/g, ' ')
        .replace(/\\[a-zA-Z]+\b/g, '')      // 其餘未知 LaTeX 指令移除
        .replace(/\$(?!\s*\d)/g, '');        // 去掉非貨幣的 $（後面不接數字，如 math 分隔符）
}
// 回覆常含 markdown（行程表/清單/粗體）→ 渲染後顯示（與 /chat 同一套，已消毒）
function md(text) {
    try { return DOMPurify.sanitize(marked.parse(delatex(text))); } catch { return String(text); }
}

const voice = useVoiceChat();
const transcript = ref('');
const agentSteps = ref([]);
// 語音喚醒（Hey Siri 式）：開啟後待機中只回應「嘿助理」開頭的話
const wakeEnabled = ref(localStorage.getItem('voice.wake') === '1');

function toggleWake() {
    wakeEnabled.value = !wakeEnabled.value;
    localStorage.setItem('voice.wake', wakeEnabled.value ? '1' : '0');
    voice.setWake(wakeEnabled.value);
}

// 打斷（barge-in）：AI 念回覆時可開口插話打斷。外放回授嚴重時可關。預設開。
const bargeEnabled = ref(localStorage.getItem('voice.barge') !== '0');
function toggleBarge() {
    bargeEnabled.value = !bargeEnabled.value;
    localStorage.setItem('voice.barge', bargeEnabled.value ? '1' : '0');
    voice.setBargeIn(bargeEnabled.value);
}

// 將 useVoiceChat 的狀態映射為能量核的四種視覺狀態
const visualStatus = computed(() => {
    if (!voice.active.value || !voice.connected.value) return 'idle';
    if (voice.speaking.value) return 'speaking';
    // 若 AI 剛回覆步驟，但還沒講話，顯示 thinking
    if (agentSteps.value.length > 0 && agentSteps.value[agentSteps.value.length - 1].includes('>>')) return 'thinking';
    return 'listening'; // 預設連線成功就是聆聽中
});

const statusText = {
    idle: 'STANDBY // 待機',
    listening: 'LISTENING // 聆聽中',
    thinking: 'PROCESSING // 處理中',
    speaking: 'SPEAKING // 回覆中',
};

function toggleConnection() {
    if (voice.active.value) {
        voice.stop();
        transcript.value = '';
        agentSteps.value = [];
    } else {
        transcript.value = '正在連線至核心…';
        agentSteps.value = [];

        voice.start(
            { mode: 'hybrid', wake: wakeEnabled.value, bargeIn: bargeEnabled.value, session: 'voice-' + (window.crypto?.randomUUID?.() || Date.now()) }, // 穩定 session id：多輪+背景任務共用同對話、結果可念回
            {
                onAiText: (t) => { transcript.value = t; agentSteps.value = []; }, // 回覆到了→清步驟，不再卡 thinking
                onTranscript: (t) => {
                    transcript.value = t;
                    agentSteps.value = []; // 新對話開始，清空舊步驟
                },
                onStep: (s) => {
                    const txt = typeof s === 'string' ? s : (s.thought || s.action || '處理中...');
                    // 去重：同一句提示（如 待機中）重複來就不疊加
                    if (agentSteps.value[agentSteps.value.length - 1] === '>> ' + txt) return;
                    agentSteps.value.push('>> ' + txt);
                    if (agentSteps.value.length > 4) agentSteps.value.shift();
                },
                onError: (err) => { transcript.value = '連線異常: ' + err; }
            }
        );
    }
}

// 監聽狀態改變以更新字幕提示
watch(() => voice.status.value, (newStatus) => {
    if (!voice.active.value) {
        transcript.value = '';
    } else if (newStatus && !voice.speaking.value) {
        // 如果還沒有收到真實字幕，顯示系統狀態
        if (!transcript.value || transcript.value.includes('連線') || transcript.value.includes('喚醒')) {
            transcript.value = newStatus;
        }
    }
});

// 長字幕自動捲到最新（串流逐句更新時跟著捲）
const transcriptBox = ref(null);
watch(transcript, async () => {
    await nextTick();
    transcriptBox.value?.scrollTo({ top: transcriptBox.value.scrollHeight, behavior: 'smooth' });
});

onUnmounted(() => {
    voice.stop();
});
</script>

<template>
    <Head title="全雙工語音連線" />

    <div class="voice-root">
        <div class="bg-glow" :class="visualStatus"></div>

        <!-- 頂部狀態列 -->
        <header class="absolute left-0 right-0 top-0 z-50 flex items-start justify-between p-5 pointer-events-none">
            <Link href="/" class="pointer-events-auto flex items-center gap-2 border border-(--ops-line) bg-black/30 px-3 py-1.5 font-mono text-[11px] tracking-[0.14em] text-(--ops-ink-dim) transition-colors hover:border-(--ops-green)/40 hover:text-(--ops-green)">
                <UiIcon name="arrowLeft" :size="12" /> OPS CONSOLE
            </Link>

            <div class="text-right font-mono">
                <div class="flex items-center justify-end gap-2 border border-(--ops-line) bg-black/30 px-3 py-1.5 text-[11px] font-bold tracking-[0.14em]" :class="{
                    'text-(--ops-ink-faint)': visualStatus === 'idle',
                    'text-(--ops-cyan)': visualStatus === 'listening',
                    'text-(--ops-amber)': visualStatus === 'thinking',
                    'text-(--ops-green)': visualStatus === 'speaking',
                }">
                    <span class="h-1.5 w-1.5 animate-pulse bg-current"></span>
                    VOICE LINK // {{ visualStatus.toUpperCase() }}
                </div>
                <div class="mt-1 text-[10px] tracking-wider text-(--ops-ink-faint)">LATENCY {{ visualStatus === 'idle' ? '--' : '24ms' }}</div>
            </div>
        </header>

        <!-- 中央視覺核心 -->
        <main class="relative z-10 flex flex-1 flex-col items-center justify-center">
            <!-- 能量核：四角括號 HUD 框 + 呼吸核心 -->
            <div class="orb-frame" :class="visualStatus">
                <span class="fc fc-tl"></span><span class="fc fc-tr"></span><span class="fc fc-bl"></span><span class="fc fc-br"></span>
                <div class="orb-container">
                    <div class="energy-orb" :class="visualStatus">
                        <div class="orb-core" :style="{
                            transform: visualStatus === 'listening' ? `scale(${1 + voice.volume.value * 2})` : '',
                            boxShadow: visualStatus === 'listening' ? `0 0 ${40 + voice.volume.value * 200}px rgba(76,194,230,${0.4 + voice.volume.value}), inset 0 0 20px rgba(0,0,0,0.5)` : ''
                        }"></div>
                        <div class="orb-ring inner"></div>
                        <div class="orb-ring outer"></div>
                    </div>
                </div>
                <div class="orb-status font-mono">{{ statusText[visualStatus] }}</div>
            </div>

            <!-- 即時字幕與步驟 -->
            <div class="mt-10 flex min-h-[140px] max-w-2xl flex-col items-center justify-start px-6 text-center">
                <!-- AI 步驟 (Trace) -->
                <div v-if="agentSteps.length > 0" class="mb-4 flex w-full flex-col items-center gap-1.5 font-mono text-[11px] text-(--ops-cyan)">
                    <div v-for="(step, idx) in agentSteps" :key="idx" class="flex items-center gap-2">
                        <span class="animate-pulse text-(--ops-green)">»</span>
                        <span class="opacity-80">{{ step.thought || step }}</span>
                    </div>
                </div>

                <div v-if="transcript" ref="transcriptBox" class="transcript-box">
                    <div class="md-body text-lg font-light tracking-wide text-(--ops-ink) md:text-xl" v-html="md(transcript)"></div>
                </div>
            </div>
        </main>

        <!-- 底部控制列 -->
        <footer class="absolute bottom-8 left-0 right-0 z-50 flex justify-center px-4">
            <div class="ctrl-bar">
                <!-- 靜音 -->
                <button class="ctl" :class="voice.isMuted.value ? 'ctl--danger' : ''" :title="voice.isMuted.value ? '已靜音 · 點此開麥' : '靜音'" @click="voice.toggleMute()">
                    <UiIcon :name="voice.isMuted.value ? 'micOff' : 'mic'" :size="17" />
                    <span class="ctl-lbl">{{ voice.isMuted.value ? 'MUTED' : 'MIC' }}</span>
                </button>

                <!-- 連線 / 斷線（主鍵） -->
                <button class="ctl ctl--main" :class="voice.active.value ? 'ctl--main-stop' : ''" :title="voice.active.value ? '中斷連線' : '開始語音連線'" @click="toggleConnection">
                    <UiIcon :name="voice.active.value ? 'x' : 'zap'" :size="22" />
                </button>

                <!-- 語音喚醒 -->
                <button class="ctl" :class="wakeEnabled ? 'ctl--amber' : ''" :title="wakeEnabled ? '語音喚醒：開（喊「嘿助理」喚醒）' : '語音喚醒：關（隨時聆聽）'" @click="toggleWake">
                    <UiIcon name="bell" :size="17" />
                    <span class="ctl-lbl">WAKE</span>
                </button>

                <!-- 可打斷 -->
                <button class="ctl" :class="bargeEnabled ? 'ctl--on' : ''" :title="bargeEnabled ? '可打斷：開（AI 念到一半可開口插話）' : '可打斷：關（外放回授嚴重時用）'" @click="toggleBarge">
                    <UiIcon name="message" :size="17" />
                    <span class="ctl-lbl">BARGE</span>
                </button>

                <!-- 即時畫面：分享螢幕給 AI 看 -->
                <button class="ctl" :class="voice.liveVision.value === 'screen' ? 'ctl--on' : ''"
                        :title="voice.liveVision.value === 'screen' ? '螢幕分享中（AI 正在看你的畫面）· 點此停止' : '分享螢幕給 AI 看'"
                        @click="voice.setLiveVision(voice.liveVision.value === 'screen' ? 'off' : 'screen')">
                    <UiIcon name="monitor" :size="17" />
                    <span class="ctl-lbl">SCREEN</span>
                </button>

                <!-- 即時畫面：鏡頭給 AI 看 -->
                <button class="ctl" :class="voice.liveVision.value === 'camera' ? 'ctl--on' : ''"
                        :title="voice.liveVision.value === 'camera' ? '鏡頭分享中（AI 正在看鏡頭）· 點此停止' : '開鏡頭給 AI 看'"
                        @click="voice.setLiveVision(voice.liveVision.value === 'camera' ? 'off' : 'camera')">
                    <UiIcon name="camera" :size="17" />
                    <span class="ctl-lbl">CAM</span>
                </button>

                <!-- 設定 -->
                <button class="ctl" title="設定" @click="router.visit('/settings')">
                    <UiIcon name="sliders" :size="17" />
                    <span class="ctl-lbl">CFG</span>
                </button>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.voice-root {
    position: relative;
    height: 100vh;
    height: 100dvh;
    width: 100vw;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* 狀態泛光（網格背景由 app.css 全域提供） */
.bg-glow {
    position: absolute;
    inset: 0;
    pointer-events: none;
    transition: all 1s ease;
    opacity: 0.5;
}
.bg-glow.idle { background: radial-gradient(circle at center, rgba(28, 39, 51, 0.5) 0%, transparent 60%); }
.bg-glow.listening { background: radial-gradient(circle at center, rgba(76, 194, 230, 0.12) 0%, transparent 70%); }
.bg-glow.thinking { background: radial-gradient(circle at center, rgba(230, 180, 80, 0.12) 0%, transparent 70%); }
.bg-glow.speaking { background: radial-gradient(circle at center, rgba(63, 220, 151, 0.12) 0%, transparent 70%); }

/* 長字幕：限制高度、可捲動、上下漸隱、進場動畫（不會蓋到底部控制列） */
.transcript-box {
    max-height: 30vh;
    overflow-y: auto;
    scroll-behavior: smooth;
    padding: 0.75rem 1rem;
    -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 16px, #000 calc(100% - 16px), transparent 100%);
    mask-image: linear-gradient(to bottom, transparent 0, #000 16px, #000 calc(100% - 16px), transparent 100%);
    animation: transcript-in 0.35s ease;
    scrollbar-width: thin;
    scrollbar-color: rgba(139, 152, 165, 0.25) transparent;
}
.transcript-box .md-body {
    word-break: break-word;
    line-height: 1.9;
    text-align: left;
}
/* markdown 元素樣式（深色主題、緊湊） */
.md-body :deep(h1), .md-body :deep(h2), .md-body :deep(h3), .md-body :deep(h4) {
    font-weight: 600;
    color: var(--ops-ink);
    margin: 0.9em 0 0.35em;
    font-size: 1.05em;
}
.md-body :deep(p) { margin: 0.4em 0; }
.md-body :deep(ul), .md-body :deep(ol) { margin: 0.4em 0; padding-left: 1.4em; }
.md-body :deep(ul) { list-style: disc; }
.md-body :deep(ol) { list-style: decimal; }
.md-body :deep(li) { margin: 0.2em 0; }
.md-body :deep(strong) { color: #fff; font-weight: 600; }
.md-body :deep(a) { color: var(--ops-cyan); text-decoration: underline; }
.md-body :deep(code) {
    background: rgba(139, 152, 165, 0.15);
    padding: 0.1em 0.4em;
    border-radius: 3px;
    font-size: 0.85em;
    font-family: var(--font-mono);
}
.md-body :deep(table) {
    border-collapse: collapse;
    margin: 0.6em auto;
    font-size: 0.85em;
}
.md-body :deep(th), .md-body :deep(td) {
    border: 1px solid var(--ops-line-strong);
    padding: 0.3em 0.7em;
}
.md-body :deep(th) { background: rgba(139, 152, 165, 0.1); }
.md-body :deep(hr) { border-color: var(--ops-line); margin: 0.8em 0; }
.md-body :deep(blockquote) {
    border-left: 2px solid color-mix(in srgb, var(--ops-cyan) 40%, transparent);
    padding-left: 0.8em;
    color: var(--ops-ink-dim);
}
@keyframes transcript-in {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: none; }
}

/* ---------- HUD 框（四角括號 + 狀態字） ---------- */
.orb-frame {
    position: relative;
    padding: 26px 34px 18px;
}
.fc {
    position: absolute;
    width: 14px;
    height: 14px;
    border-color: var(--ops-line-strong);
    border-style: solid;
    border-width: 0;
    transition: border-color 0.6s ease;
}
.fc-tl { top: 0; left: 0; border-top-width: 1px; border-left-width: 1px; }
.fc-tr { top: 0; right: 0; border-top-width: 1px; border-right-width: 1px; }
.fc-bl { bottom: 0; left: 0; border-bottom-width: 1px; border-left-width: 1px; }
.fc-br { bottom: 0; right: 0; border-bottom-width: 1px; border-right-width: 1px; }
.orb-frame.listening .fc { border-color: color-mix(in srgb, var(--ops-cyan) 60%, transparent); }
.orb-frame.thinking .fc { border-color: color-mix(in srgb, var(--ops-amber) 60%, transparent); }
.orb-frame.speaking .fc { border-color: color-mix(in srgb, var(--ops-green) 60%, transparent); }

.orb-status {
    margin-top: 14px;
    text-align: center;
    font-size: 0.62rem;
    letter-spacing: 0.2em;
    color: var(--ops-ink-faint);
}
.orb-frame.listening .orb-status { color: var(--ops-cyan); }
.orb-frame.thinking .orb-status { color: var(--ops-amber); }
.orb-frame.speaking .orb-status { color: var(--ops-green); }

/* ---------- 能量核 ---------- */
.orb-container {
    position: relative;
    width: 200px;
    height: 200px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.energy-orb {
    position: relative;
    width: 110px;
    height: 110px;
    border-radius: 50%;
    transition: all 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

/* 狀態：閒置 */
.energy-orb.idle .orb-core {
    background: radial-gradient(circle at 30% 30%, #2b3947, #0a1016);
    box-shadow: 0 0 20px rgba(43, 57, 71, 0.3), inset 0 0 20px rgba(0, 0, 0, 0.8);
    animation: morph-idle 8s ease-in-out infinite;
}

/* 狀態：聆聽 (cyan) */
.energy-orb.listening .orb-core {
    background: radial-gradient(circle at 30% 30%, #4cc2e6, #0e5872);
    box-shadow: 0 0 40px rgba(76, 194, 230, 0.45), inset 0 0 20px rgba(0, 0, 0, 0.5);
    animation: morph-active 3s ease-in-out infinite alternate;
}
.energy-orb.listening .orb-ring.outer {
    border-color: rgba(76, 194, 230, 0.3);
    animation: spin 6s linear infinite;
    opacity: 1;
}

/* 狀態：思考 (amber) */
.energy-orb.thinking .orb-core {
    background: radial-gradient(circle at 30% 30%, #e6b450, #7a5a1d);
    box-shadow: 0 0 50px rgba(230, 180, 80, 0.5), inset 0 0 30px rgba(0, 0, 0, 0.5);
    animation: morph-thinking 2s ease-in-out infinite alternate;
}
.energy-orb.thinking .orb-ring.inner {
    border-color: rgba(230, 180, 80, 0.4);
    animation: spin-reverse 2s linear infinite;
    opacity: 1;
}
.energy-orb.thinking .orb-ring.outer {
    border-color: rgba(230, 180, 80, 0.2);
    animation: spin 3s linear infinite;
    opacity: 1;
}

/* 狀態：說話 (green) */
.energy-orb.speaking .orb-core {
    background: radial-gradient(circle at 30% 30%, #3fdc97, #0d5c3c);
    box-shadow: 0 0 60px rgba(63, 220, 151, 0.6), inset 0 0 20px rgba(0, 0, 0, 0.3);
    animation: morph-speaking 0.5s ease-in-out infinite alternate;
}
.energy-orb.speaking .orb-ring {
    opacity: 0;
}
.energy-orb.speaking::after {
    content: '';
    position: absolute;
    inset: -50px;
    border-radius: 50%;
    border: 2px solid rgba(63, 220, 151, 0.5);
    animation: pulse-wave 1.5s cubic-bezier(0, 0, 0.2, 1) infinite;
}

/* 球體核心元件 */
.orb-core {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    transition: transform 0.1s linear, box-shadow 0.1s linear;
    z-index: 10;
}

/* 環繞掃描線 */
.orb-ring {
    position: absolute;
    border-radius: 50%;
    border: 2px dashed transparent;
    transition: opacity 0.3s;
    opacity: 0;
    pointer-events: none;
}
.orb-ring.inner {
    inset: -15px;
    border-width: 2px;
}
.orb-ring.outer {
    inset: -30px;
    border-width: 1px;
}

/* 動畫 Keyframes */
@keyframes morph-idle {
    0% { border-radius: 50%; transform: scale(1); }
    33% { border-radius: 55% 45% 45% 55% / 55% 45% 55% 45%; }
    66% { border-radius: 45% 55% 55% 45% / 45% 55% 45% 55%; transform: scale(0.98); }
    100% { border-radius: 50%; transform: scale(1); }
}
@keyframes morph-active {
    0% { border-radius: 45% 55% 45% 55% / 55% 45% 55% 45%; }
    100% { border-radius: 55% 45% 55% 45% / 45% 55% 45% 55%; }
}
@keyframes morph-thinking {
    0% { border-radius: 40% 60% 40% 60% / 60% 40% 60% 40%; transform: scale(1) rotate(0deg); }
    100% { border-radius: 60% 40% 60% 40% / 40% 60% 40% 60%; transform: scale(1.05) rotate(10deg); }
}
@keyframes morph-speaking {
    0% { border-radius: 48% 52% 48% 52% / 52% 48% 52% 48%; transform: scale(1.1); }
    100% { border-radius: 52% 48% 52% 48% / 48% 52% 52% 48%; transform: scale(1.25); }
}
@keyframes spin { 100% { transform: rotate(360deg); } }
@keyframes spin-reverse { 100% { transform: rotate(-360deg); } }
@keyframes pulse-wave {
    0% { transform: scale(0.8); opacity: 0.8; border-width: 4px; }
    100% { transform: scale(2.5); opacity: 0; border-width: 0px; }
}

/* ---------- 底部控制列 ---------- */
.ctrl-bar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    max-width: 100%;
    overflow-x: auto;
    border: 1px solid var(--ops-line);
    background: rgba(5, 9, 13, 0.82);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    padding: 0.6rem 0.75rem;
}
.ctl {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    width: 52px;
    height: 48px;
    flex: 0 0 auto;
    border: 1px solid var(--ops-line);
    background: rgba(255, 255, 255, 0.02);
    color: var(--ops-ink-dim);
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s, background 0.15s;
}
.ctl:hover { color: var(--ops-ink); border-color: var(--ops-line-strong); background: rgba(255, 255, 255, 0.04); }
.ctl-lbl {
    font-family: var(--font-mono);
    font-size: 0.5rem;
    letter-spacing: 0.12em;
}
.ctl--on {
    color: var(--ops-green);
    border-color: color-mix(in srgb, var(--ops-green) 45%, transparent);
    background: rgba(63, 220, 151, 0.08);
}
.ctl--amber {
    color: var(--ops-amber);
    border-color: color-mix(in srgb, var(--ops-amber) 45%, transparent);
    background: rgba(230, 180, 80, 0.08);
}
.ctl--danger {
    color: var(--ops-red);
    border-color: color-mix(in srgb, var(--ops-red) 45%, transparent);
    background: rgba(240, 106, 106, 0.1);
}
.ctl--main {
    width: 62px;
    height: 56px;
    color: #04110b;
    background: var(--ops-green);
    border-color: color-mix(in srgb, var(--ops-green) 70%, transparent);
}
.ctl--main:hover {
    color: #04110b;
    background: var(--ops-green);
    filter: brightness(1.12);
    box-shadow: 0 0 20px -4px rgba(63, 220, 151, 0.6);
}
.ctl--main-stop {
    color: #fff;
    background: var(--ops-red);
    border-color: color-mix(in srgb, var(--ops-red) 70%, transparent);
}
.ctl--main-stop:hover {
    background: var(--ops-red);
    color: #fff;
    box-shadow: 0 0 20px -4px rgba(240, 106, 106, 0.6);
}
</style>
