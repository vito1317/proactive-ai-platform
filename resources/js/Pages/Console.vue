<script setup>
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PipelineFlow from '../Components/PipelineFlow.vue';

const props = defineProps({
    platform: { type: String, default: 'PAI' },
    domains: { type: Array, default: () => [] },
    commandTargets: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    runs: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    installCommand: { type: String, default: '' },
    gatewayInstallCommand: { type: String, default: '' },
    learnedSkills: { type: Array, default: () => [] },
    userMemories: { type: Array, default: () => [] },
    llmUsage: { type: Object, default: () => ({}) },
    scheduledTasks: { type: Array, default: () => [] },
});

const copied = ref(false);
function copyInstall() {
    navigator.clipboard?.writeText(props.installCommand);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1500);
}

const copiedGw = ref(false);
function copyGateway() {
    navigator.clipboard?.writeText(props.gatewayInstallCommand);
    copiedGw.value = true;
    setTimeout(() => { copiedGw.value = false; }, 1500);
}

/* ---------- Android 節點配對 ---------- */
const apkUrl = 'https://github.com/vito1317/pai-gateway-android/releases/download/latest/app-debug.apk';
const pair = ref({ qr: '', code: '' });
const copiedPair = ref(false);
async function loadPair() {
    try {
        const r = await fetch('/api/gateway/pair-code?format=json', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        pair.value = await r.json();
    } catch (e) { /* ignore */ }
}
function copyPair() {
    navigator.clipboard?.writeText(pair.value.code);
    copiedPair.value = true;
    setTimeout(() => { copiedPair.value = false; }, 1500);
}

/* ---------- 節點 / Gateway 連線狀態 ---------- */
const nodes = ref([]);
const nodesLoading = ref(false);
async function fetchNodes() {
    nodesLoading.value = true;
    try {
        const r = await fetch('/mcp/health', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await r.json();
        nodes.value = d.nodes || [];
    } catch (e) { /* ignore */ }
    nodesLoading.value = false;
}

const expanded = ref(new Set());
const toggle = (id) => {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
};

const page = usePage();
const flash = computed(() => page.props.flash || {});
const auth = computed(() => page.props.auth || {});
const showBell = ref(false);
function markRead() {
    router.post('/notifications/read', {}, { preserveScroll: true, onSuccess: () => { showBell.value = false; } });
}
function logout() {
    router.post('/logout');
}

/* ---------- 流程圖 stages（由 stats 推導） ---------- */
const s = (k) => props.stats[k] ?? 0;
const stages = computed(() => {
    const total = s('total');
    const oriented = s('normalized') + s('routed');
    const decided = s('routed');
    return [
        { key: 'l1', label: 'L1 感知', sub: 'Observe', icon: '📡', accent: '#22d3ee', count: total, active: total > 0 },
        { key: 'l2', label: 'L2 記憶', sub: 'Orient', icon: '💾', accent: '#38bdf8', count: oriented, active: oriented > 0 },
        { key: 'l3', label: 'L3 認知', sub: 'Decide', icon: '🧠', accent: '#818cf8', count: decided, active: decided > 0 },
        { key: 'l4', label: 'L4 行動', sub: 'Act', icon: '⚡', accent: '#34d399', count: s('acted'), active: s('acted') > 0 },
        { key: 'l5', label: 'L5 護欄', sub: 'Guardrail', icon: '🛡️', accent: '#fb7185', count: s('hitl'), active: s('hitl') > 0 },
    ];
});
const intensity = computed(() => Math.min(1, s('total') / 20));

/* ---------- 指令面板（單一輸入，AI 自動判斷） ---------- */
const askForm = useForm({ message: '' });
const examples = [
    '有一台主機 host-7 好像中了勒索病毒，幫我處理',     // → 任務
    '監控資料庫慢查詢，超過門檻就告警並建議加索引',     // → 新增領域
    '我的 Telegram bot token 是 123456:ABC，chat id 是 987654321', // → 設定通知
];
function fillExample(text) {
    askForm.message = text;
}
function submitPanel() {
    if (!askForm.message.trim()) return;
    askForm.post('/console/ask', {
        preserveScroll: true,
        onSuccess: () => { askForm.reset('message'); refresh(); },
    });
}
function onEnter(e) {
    if (e.isComposing || e.keyCode === 229) return; // 中文選字中不送出
    e.preventDefault();
    submitPanel();
}

/* ---------- HITL 核准 / 駁回 ---------- */
function decide(runId, index, decision) {
    router.post(`/console/runs/${runId}/decision`, { index, decision }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => refresh(),
    });
}

/* ---------- 即時輪詢 ---------- */
let timer = null;
function refresh() {
    router.reload({ only: ['events', 'runs', 'stats', 'auth'], preserveScroll: true, preserveState: true });
}

/* ---------- AI 思考動畫 (終端機打字效果) ---------- */
const activeRun = computed(() => props.runs.find(r => r.status === 'running'));
const thinkingSteps = ['載入領域知識...', '分析上下文關聯...', '評估可用工具...', '構建執行計畫...', '發送 API 請求...', '等待結果回傳...', '驗證護欄規則...'];
const currentThinkingStep = ref(thinkingSteps[0]);
let thinkingTimer = null;

onMounted(() => {
    fetchNodes();
    loadPair();
    timer = setInterval(refresh, 4000);
    thinkingTimer = setInterval(() => {
        if (activeRun.value) {
            currentThinkingStep.value = thinkingSteps[Math.floor(Math.random() * thinkingSteps.length)];
        }
    }, 2500); // 每 2.5 秒切換一次假動作
});
onUnmounted(() => { 
    clearInterval(timer); 
    clearInterval(thinkingTimer);
});

/* ---------- 樣式 ---------- */
const severityClass = (x) => ({
    low: 'bg-slate-700/50 text-slate-300', medium: 'bg-amber-500/20 text-amber-300',
    high: 'bg-orange-500/20 text-orange-300', critical: 'bg-red-500/25 text-red-300',
}[x] || 'bg-slate-700/50 text-slate-400');
const statusClass = (x) => ({
    received: 'bg-slate-600/40 text-slate-300', normalized: 'bg-sky-500/20 text-sky-300',
    routed: 'bg-emerald-500/20 text-emerald-300', ignored: 'bg-slate-700/40 text-slate-500',
    failed: 'bg-red-500/25 text-red-300',
}[x] || 'bg-slate-700/40 text-slate-400');
const autonomyClass = (x) => ({
    copilot: 'bg-slate-600/40 text-slate-300', supervisor: 'bg-indigo-500/25 text-indigo-300',
    autopilot: 'bg-emerald-500/25 text-emerald-300',
}[x] || 'bg-slate-700/40 text-slate-400');
const runStatusClass = (x) => ({
    running: 'bg-sky-500/20 text-sky-300', awaiting_hitl: 'bg-amber-500/20 text-amber-300',
    completed: 'bg-emerald-500/20 text-emerald-300', failed: 'bg-red-500/25 text-red-300',
}[x] || 'bg-slate-700/40 text-slate-400');
const runStatusLabel = (x) => ({
    running: '思考中…', awaiting_hitl: '待人類核准', completed: '已完成', failed: '失敗',
}[x] || x);
const actionStatusClass = (x) => ({
    executed: 'bg-emerald-500/20 text-emerald-300', awaiting_approval: 'bg-amber-500/20 text-amber-300',
    rejected: 'bg-red-500/25 text-red-300', proposed: 'bg-slate-600/40 text-slate-300',
    suggested: 'bg-sky-500/20 text-sky-300', observed: 'bg-slate-600/30 text-slate-400',
}[x] || 'bg-slate-700/40 text-slate-400');
</script>

<style scoped>
@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}
.typing-cursor {
    animation: blink 1s step-end infinite;
    font-weight: bold;
    color: #38bdf8; /* sky-400 */
}

@keyframes scan-line {
    0% { transform: translateY(-100%); }
    100% { transform: translateY(100%); }
}
.ooda-badge {
    position: relative;
    overflow: hidden;
}
.ooda-badge::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; height: 100%;
    background: linear-gradient(to bottom, transparent, rgba(56,189,248,0.4), transparent);
    animation: scan-line 2s linear infinite;
    pointer-events: none;
}
</style>

<template>
    <Head title="中控台" />

    <div class="console">
        <!-- 背景光暈 -->
        <div class="bg-glow"></div>

        <div class="relative z-10 mx-auto max-w-7xl px-6 py-6">
            <!-- header -->
            <header class="flex flex-wrap items-center justify-between gap-3 pb-6">
                <div class="flex items-center gap-3">
                    <div class="ooda-badge"><span>OODA</span></div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-white">
                            {{ platform }} <span class="text-indigo-400">主動式 AI 中控台</span>
                        </h1>
                        <p class="text-sm text-slate-400">看見並指揮 AI · 即時感知 → 規劃 → 行動</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="hidden items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs text-emerald-300 sm:flex">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        LIVE
                    </div>

                    <!-- 通知鈴鐺 -->
                    <div class="relative">
                        <button class="relative rounded-full border border-white/10 bg-white/5 px-3 py-1 text-sm text-slate-300 hover:text-white" @click="showBell = !showBell">
                            🔔
                            <span v-if="auth.unread" class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ auth.unread }}</span>
                        </button>
                        <div v-if="showBell" class="absolute right-0 z-20 mt-2 w-80 rounded-xl border border-white/10 bg-slate-900/95 p-2 shadow-xl backdrop-blur">
                            <div class="flex items-center justify-between px-2 py-1">
                                <span class="text-xs font-semibold text-slate-300">通知</span>
                                <button v-if="auth.unread" class="text-xs text-indigo-400 hover:text-indigo-300" @click="markRead">全部標記已讀</button>
                            </div>
                            <div v-if="!auth.notifications?.length" class="px-2 py-4 text-center text-xs text-slate-500">沒有通知</div>
                            <div v-for="n in auth.notifications" :key="n.id" class="rounded-lg px-2 py-2 text-xs" :class="n.read ? 'text-slate-500' : 'bg-white/5 text-slate-200'">
                                <span v-if="!n.read" class="mr-1 text-amber-400">●</span>{{ n.message }}
                                <div class="text-[10px] text-slate-500">{{ n.at }}</div>
                            </div>
                        </div>
                    </div>

                    <Link href="/voice" class="rounded-full border border-sky-500/40 bg-sky-500/15 px-3 py-1 text-xs text-sky-200 hover:text-white">🎙️ 語音連線</Link>
                    <Link href="/chat" class="rounded-full border border-indigo-500/40 bg-indigo-500/15 px-3 py-1 text-xs text-indigo-200 hover:text-white">💬 對話</Link>
                    <Link href="/automations" class="rounded-full border border-fuchsia-500/40 bg-fuchsia-500/15 px-3 py-1 text-xs text-fuchsia-200 hover:text-white">🤖 自動化</Link>
                    <Link href="/packs" class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-slate-300 hover:text-white">🧩 領域包</Link>
                    <Link href="/settings" class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-slate-300 hover:text-white">⚙ 設定</Link>
                    <button class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-slate-400 hover:text-white" :title="auth.user?.email" @click="logout">登出</button>
                </div>
            </header>

            <!-- flash -->
            <transition name="fade">
                <div v-if="flash.success" class="mb-5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    {{ flash.success }}
                </div>
            </transition>

            <!-- 流程動畫圖 -->
            <section class="glass mb-6 p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-300">Agentic Pipeline · 即時流向</h2>
                    <span class="text-xs text-slate-500">事件沿五層流動 → 光點密度反映吞吐</span>
                </div>
                <PipelineFlow :stages="stages" :intensity="intensity" />
            </section>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- 指令 + 領域 -->
                <section class="space-y-6 lg:col-span-1">
                    <div class="glass p-5">
                        <h2 class="flex items-center gap-2 font-semibold text-white">
                            <span class="text-indigo-400">💬</span> 指揮 AI
                            <span class="rounded-full bg-indigo-500/20 px-2 py-0.5 text-[10px] text-indigo-300">🪄 自動判斷</span>
                        </h2>
                        <p class="mt-1 text-xs text-slate-400">
                            用白話說一句話就好——AI 會自動判斷要「執行任務、新增領域、還是設定通知」並處理。
                        </p>
                        <form class="mt-3 space-y-3" @submit.prevent="submitPanel()">
                            <textarea
                                v-model="askForm.message"
                                rows="3"
                                placeholder="例如：主機中毒幫我處理 / 監控資料庫慢查詢 / 設定我的 Telegram 通知…"
                                class="inp"
                                @keydown.enter="onEnter"
                            ></textarea>
                            <button type="submit" :disabled="askForm.processing || !askForm.message.trim()" class="btn-primary">
                                <span v-if="askForm.processing">AI 判斷中…</span>
                                <span v-else>🪄 交給 AI 自動處理</span>
                            </button>
                        </form>
                        <div class="mt-3">
                            <div class="mb-1 text-xs text-slate-500">試試看：</div>
                            <div class="flex flex-col gap-1.5">
                                <button
                                    v-for="(ex, i) in examples"
                                    :key="i"
                                    class="rounded-lg border border-white/5 bg-white/5 px-3 py-1.5 text-left text-xs text-slate-300 hover:border-indigo-500/40 hover:text-white"
                                    @click="fillExample(ex)"
                                >{{ ex }}</button>
                            </div>
                        </div>
                    </div>

                    <div class="glass p-5">
                        <h2 class="font-semibold text-white">已載入領域</h2>
                        <ul class="mt-3 space-y-2">
                            <li v-for="d in domains" :key="d.domain" class="rounded-lg border border-white/5 bg-white/5 px-3 py-2">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono text-sm text-indigo-300">{{ d.domain }}</span>
                                    <span class="rounded px-2 py-0.5 text-xs" :class="autonomyClass(d.autonomy)">{{ d.autonomy }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">{{ d.description }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ d.agents.length }} agents · {{ d.events.length }} 事件 · {{ d.high_risk_tools.length }} 高風險工具</p>
                            </li>
                        </ul>
                    </div>

                    <!-- 節點 / Gateway 連線狀態 -->
                    <div class="glass p-5">
                        <div class="flex items-center justify-between">
                            <h2 class="flex items-center gap-2 font-semibold text-white">🛰️ 節點連線狀態</h2>
                            <button class="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs text-slate-300 hover:text-white" @click="fetchNodes">{{ nodesLoading ? '檢測中…' : '↻ 重新檢測' }}</button>
                        </div>
                        <p v-if="!nodes.length && !nodesLoading" class="mt-2 text-xs text-slate-500">尚無節點。用下方指令在其他機器接上 Gateway。</p>
                        <ul class="mt-3 space-y-2">
                            <li v-for="n in nodes" :key="n.name" class="flex items-center gap-2 rounded-lg border border-white/5 bg-white/5 px-3 py-2 text-sm">
                                <span class="text-base">{{ n.ok ? '🟢' : '🔴' }}</span>
                                <span class="font-medium text-white">{{ n.name }}</span>
                                <span class="text-xs text-slate-400">{{ n.ok ? (n.ms + 'ms · ' + (n.tools?.length || 0) + ' 工具') : (n.error || '離線') }}</span>
                            </li>
                        </ul>
                    </div>

                    <!-- 排定的定時任務 -->
                    <div class="glass p-5">
                        <h2 class="flex items-center gap-2 font-semibold text-white">⏰ 排定的行程 / 定時任務</h2>
                        <ul v-if="scheduledTasks.length" class="mt-3 space-y-2">
                            <li v-for="t in scheduledTasks" :key="t.id" class="flex items-center justify-between gap-2 rounded-lg border border-white/10 bg-black/30 px-3 py-2">
                                <div class="min-w-0">
                                    <div class="text-sm text-white truncate">{{ t.command }}</div>
                                    <div class="text-[11px] text-cyan-300">{{ t.run_at }}<span v-if="t.recur === 'daily'" class="ml-1 text-emerald-300">· 每天</span></div>
                                </div>
                                <button class="shrink-0 rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-[11px] text-slate-300 hover:text-red-300"
                                    @click="router.post('/console/scheduled/' + t.id + '/cancel', {}, { preserveScroll: true })">取消</button>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-xs text-slate-500">目前沒有排定的定時任務。對 AI 說「每天早上八點報天氣」「明天三點提醒我開會」就會出現在這。</p>
                    </div>

                    <!-- #9 LLM 用量觀測（平台層級，僅 admin 顯示） -->
                    <div v-if="$page.props.auth?.user?.is_admin" class="glass p-5">
                        <h2 class="flex items-center gap-2 font-semibold text-white">📊 AI 用量（今日）</h2>
                        <div class="mt-3 grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-lg border border-white/10 bg-black/30 py-3">
                                <div class="text-xl font-bold text-cyan-300">{{ llmUsage.today_calls ?? 0 }}</div>
                                <div class="text-[11px] text-slate-400">次呼叫</div>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-black/30 py-3">
                                <div class="text-xl font-bold text-cyan-300">{{ ((llmUsage.today_tokens ?? 0) / 1000).toFixed(1) }}k</div>
                                <div class="text-[11px] text-slate-400">tokens</div>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-black/30 py-3">
                                <div class="text-xl font-bold text-cyan-300">{{ llmUsage.today_avg_ms ?? 0 }}<span class="text-xs">ms</span></div>
                                <div class="text-[11px] text-slate-400">平均延遲</div>
                            </div>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-500">本週 {{ llmUsage.week_calls ?? 0 }} 次 · {{ ((llmUsage.week_tokens ?? 0) / 1000).toFixed(0) }}k tokens</p>
                    </div>

                    <!-- 自我改進：AI 學會的做法 -->
                    <div class="glass p-5">
                        <h2 class="flex items-center gap-2 font-semibold text-white">🧠 AI 學會的技能（自我改進）</h2>
                        <p class="mt-1 text-xs text-slate-400">完成多步任務後，AI 會把成功的做法學起來，下次同類需求自動套用、更快更穩。</p>
                        <ul v-if="learnedSkills.length" class="mt-3 space-y-2">
                            <li v-for="s in learnedSkills" :key="s.id" class="rounded-lg border border-white/10 bg-black/30 px-3 py-2">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-medium text-cyan-300">▶ {{ s.name }}</span>
                                    <span class="shrink-0 text-[11px] text-slate-500">用過 {{ s.uses }} 次</span>
                                </div>
                                <p class="mt-0.5 text-[11px] text-slate-400">{{ s.when_to_use }}</p>
                                <p class="mt-1 text-[11px] leading-relaxed text-slate-300 whitespace-pre-wrap">{{ s.steps }}</p>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-xs text-slate-500">尚未學會任何做法——叫 AI 完成幾個多步驟任務（排行程、傳訊息給多人…）就會開始累積。</p>

                        <h3 class="mt-5 flex items-center gap-2 text-sm font-semibold text-white">📌 關於你的長期記憶</h3>
                        <ul v-if="userMemories.length" class="mt-2 flex flex-wrap gap-2">
                            <li v-for="m in userMemories" :key="m.id" class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] text-slate-300">{{ m.content }}</li>
                        </ul>
                        <p v-else class="mt-2 text-xs text-slate-500">還沒記住關於你的事。對 AI 說「記住我住汐止」之類就會記起來。</p>
                    </div>

                    <!-- 一鍵安裝 -->
                    <div class="glass p-5">
                        <h2 class="flex items-center gap-2 font-semibold text-white">📦 一鍵安裝</h2>
                        <p class="mt-1 text-xs text-slate-400">在新機器部署整套平台：</p>
                        <div class="mt-2 flex items-center gap-2">
                            <code class="flex-1 overflow-x-auto whitespace-nowrap rounded-lg border border-white/10 bg-black/40 px-3 py-2 text-[11px] text-emerald-300">{{ installCommand }}</code>
                            <button class="shrink-0 rounded-lg border border-white/10 bg-white/5 px-2 py-2 text-xs text-slate-300 hover:text-white" @click="copyInstall">
                                {{ copied ? '✓ 已複製' : '複製' }}
                            </button>
                        </div>

                        <p class="mt-4 text-xs text-slate-400">🛰️ 在其他節點 / Mac 自動接線（裝 gateway + cloudflared 通道 + 自動註冊，一行搞定）：</p>
                        <div class="mt-2 flex items-center gap-2">
                            <code class="flex-1 overflow-x-auto whitespace-nowrap rounded-lg border border-white/10 bg-black/40 px-3 py-2 text-[11px] text-sky-300">{{ gatewayInstallCommand }}</code>
                            <button class="shrink-0 rounded-lg border border-white/10 bg-white/5 px-2 py-2 text-xs text-slate-300 hover:text-white" @click="copyGateway">
                                {{ copiedGw ? '✓ 已複製' : '複製' }}
                            </button>
                        </div>
                        <p class="mt-1 text-[10px] text-slate-500">在目標機器（Mac/Linux）貼上執行：自動裝好、開 cloudflared 公網通道、註冊成本平台節點。之後說「在 &lt;主機名&gt; 上打開 chrome」即可。管理：<code class="text-slate-400">./gw status|stop|port N</code></p>
                    </div>

                    <!-- Android 節點配對 -->
                    <div class="glass p-5">
                        <h2 class="flex items-center gap-2 font-semibold text-white">📱 Android 節點</h2>
                        <p class="mt-1 text-xs text-slate-400">把手機變成節點：下載 App → 掃描下方 QR → 一鍵配對串接。</p>
                        <div class="mt-3 flex flex-col items-center gap-3 sm:flex-row sm:items-start">
                            <img v-if="pair.qr" :src="pair.qr" alt="配對 QR" class="h-40 w-40 shrink-0 rounded-xl bg-white p-2" />
                            <div v-else class="flex h-40 w-40 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-black/40 text-xs text-slate-500">
                                <button class="rounded-lg bg-white/10 px-3 py-2 text-slate-200 hover:bg-white/20" @click="loadPair">產生配對 QR</button>
                            </div>
                            <div class="min-w-0 flex-1 space-y-2">
                                <a :href="apkUrl" target="_blank" class="block rounded-lg bg-emerald-500/20 px-3 py-2 text-center text-sm text-emerald-300 hover:bg-emerald-500/30">⬇️ 下載 Android App（APK）</a>
                                <p class="text-[11px] text-slate-400">App 內「節點」分頁 →「📷 掃描 QR 一鍵配對」掃上面的 QR 即可。</p>
                                <button v-if="pair.code" class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300 hover:text-white" @click="copyPair">
                                    {{ copiedPair ? '✓ 已複製配對碼' : '📋 複製配對碼（手動貼上用）' }}
                                </button>
                                <p class="text-[10px] text-amber-400/80">⚠️ 配對碼含註冊金鑰，請勿外流。</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 事件流 -->
                <section class="lg:col-span-2">
                    <div class="glass overflow-hidden">
                        <div class="flex items-center justify-between border-b border-white/10 px-5 py-3">
                            <h2 class="font-semibold text-white">事件流 · Observe → Orient → Route</h2>
                            <span class="text-xs text-slate-500">最新 {{ events.length }} 筆</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="text-xs uppercase text-slate-500">
                                    <tr class="border-b border-white/5">
                                        <th class="px-4 py-2">#</th><th class="px-4 py-2">主題 / 來源</th>
                                        <th class="px-4 py-2">意圖</th><th class="px-4 py-2">嚴重性</th>
                                        <th class="px-4 py-2">領域</th><th class="px-4 py-2">狀態</th><th class="px-4 py-2">時間</th>
                                    </tr>
                                </thead>
                                <TransitionGroup tag="tbody" name="row">
                                    <tr v-for="e in events" :key="e.id" class="border-b border-white/5 hover:bg-white/5">
                                        <td class="px-4 py-2 tabular-nums text-slate-500">{{ e.id }}</td>
                                        <td class="px-4 py-2"><div class="font-mono text-xs text-slate-200">{{ e.topic }}</div><div class="text-xs text-slate-500">{{ e.source }}</div></td>
                                        <td class="px-4 py-2 text-xs text-slate-300">{{ e.intent ?? '—' }}</td>
                                        <td class="px-4 py-2"><span v-if="e.severity" class="rounded px-2 py-0.5 text-xs" :class="severityClass(e.severity)">{{ e.severity }}</span><span v-else class="text-slate-600">—</span></td>
                                        <td class="px-4 py-2 font-mono text-xs text-slate-300">{{ e.domain ?? '—' }}</td>
                                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-xs" :class="statusClass(e.status)">{{ e.status }}</span></td>
                                        <td class="px-4 py-2 text-xs text-slate-500">{{ e.at }}</td>
                                    </tr>
                                    <tr v-if="!events.length" key="empty">
                                        <td colspan="7" class="px-4 py-12 text-center text-slate-500">尚無事件。用左側「下指令」注入一筆，或 POST /webhooks/{source}。</td>
                                    </tr>
                                </TransitionGroup>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <!-- AI 運行 / 思考軌跡 -->
            <section class="glass mt-6 overflow-hidden">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-3">
                    <h2 class="font-semibold text-white">🧠 AI 認知運行 · Decide → Act → Guardrail</h2>
                    <span class="text-xs text-slate-500">{{ runs.length }} 筆運行</span>
                </div>

                <!-- AI 核心終端機狀態 -->
                <div class="border-b border-white/5 bg-slate-900/50 px-5 py-3 font-mono text-xs">
                    <div v-if="activeRun" class="flex items-center gap-3 text-sky-400">
                        <span class="flex h-2 w-2 items-center justify-center">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-sky-500"></span>
                        </span>
                        <div class="flex flex-col">
                            <span class="font-bold">SYSTEM_ACTIVE // 執行中</span>
                            <span class="mt-1 text-slate-300">
                                > [{{ activeRun.goal }}] <span class="text-sky-300">{{ currentThinkingStep }}</span><span class="typing-cursor">_</span>
                            </span>
                        </div>
                    </div>
                    <div v-else class="flex items-center gap-3 text-slate-500">
                        <span class="h-2 w-2 rounded-full bg-slate-700"></span>
                        <span>SYSTEM_IDLE // 待機中</span>
                    </div>
                </div>

                <div v-if="!runs.length" class="px-5 py-10 text-center text-slate-500">
                    尚無認知運行。送出指令後，協調者會在這裡展開 ReAct 推理。
                </div>

                <div v-for="r in runs" :key="r.id" class="border-b border-white/5">
                    <button class="flex w-full items-center gap-3 px-5 py-3 text-left hover:bg-white/5" @click="toggle(r.id)">
                        <span class="font-mono text-xs text-indigo-300">{{ r.coordinator }}</span>
                        <span class="text-xs text-slate-500">←</span>
                        <span class="font-mono text-xs text-slate-300">{{ r.topic }}</span>
                        <span class="rounded px-2 py-0.5 text-xs" :class="runStatusClass(r.status)">
                            <span v-if="r.status === 'running'" class="mr-1 inline-block animate-pulse">●</span>{{ runStatusLabel(r.status) }}
                        </span>
                        <span class="ml-auto flex items-center gap-3 text-xs text-slate-500">
                            <span>{{ r.findings?.length || 0 }} 發現</span>
                            <span>{{ r.actions?.length || 0 }} 動作</span>
                            <span>{{ r.tokens }} tok</span>
                            <span class="text-slate-600">{{ expanded.has(r.id) ? '▲' : '▼' }}</span>
                        </span>
                    </button>

                    <div v-if="r.actions?.length" class="space-y-1 px-5 pb-3">
                        <div v-for="(a, i) in r.actions" :key="i" class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs" :class="actionStatusClass(a.status)">
                                ⚡ {{ a.action }}
                                <span class="opacity-70">· {{ a.status === 'awaiting_approval' ? '待核准' : a.status === 'executed' ? '已執行' : a.status === 'rejected' ? '已駁回' : a.status === 'suggested' ? '建議（未執行）' : a.status === 'observed' ? '僅記錄' : a.status }}</span>
                                <template v-if="a.status === 'awaiting_approval'">
                                    <button class="ml-1 rounded bg-emerald-500/30 px-1.5 py-0.5 text-emerald-200 hover:bg-emerald-500/50" title="核准並執行" @click.stop="decide(r.id, i, 'approve')">✓ 核准</button>
                                    <button class="rounded bg-red-500/30 px-1.5 py-0.5 text-red-200 hover:bg-red-500/50" title="駁回" @click.stop="decide(r.id, i, 'reject')">✗ 駁回</button>
                                </template>
                            </span>
                            <span v-if="a.result" class="text-xs text-emerald-300/70">→ {{ a.result }}</span>
                        </div>
                    </div>

                    <div v-if="expanded.has(r.id)" class="space-y-4 bg-black/20 px-5 py-4">
                        <div v-if="r.summary" class="rounded-lg border border-white/5 bg-white/5 px-3 py-2 text-sm text-slate-300">
                            <span class="text-xs text-slate-500">總結 · </span>{{ r.summary }}
                        </div>
                        <div v-if="r.findings?.length">
                            <div class="mb-1 text-xs uppercase tracking-wider text-slate-500">發現</div>
                            <ul class="space-y-1">
                                <li v-for="(f, i) in r.findings" :key="i" class="text-sm text-slate-300">• {{ f }}</li>
                            </ul>
                        </div>
                        <div>
                            <div class="mb-2 text-xs uppercase tracking-wider text-slate-500">ReAct 軌跡</div>
                            <ol class="space-y-3">
                                <li v-for="(s, i) in r.steps" :key="i" class="border-l-2 border-indigo-500/30 pl-4">
                                    <div class="flex items-center gap-2">
                                        <span class="rounded bg-indigo-500/20 px-2 py-0.5 font-mono text-xs text-indigo-300">{{ s.action }}</span>
                                        <span v-if="s.action_input && Object.keys(s.action_input).length" class="font-mono text-xs text-slate-500">{{ JSON.stringify(s.action_input) }}</span>
                                    </div>
                                    <p v-if="s.thought" class="mt-1 text-xs text-slate-400">💭 {{ s.thought }}</p>
                                    <p v-if="s.observation" class="mt-1 text-xs text-emerald-300/80">👁 {{ s.observation }}</p>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.console {
    position: relative;
    min-height: 100vh;
    background: #020617;
    color: #e2e8f0;
    overflow: hidden;
}
.bg-glow {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(600px circle at 15% 0%, rgba(99, 102, 241, 0.18), transparent 45%),
        radial-gradient(700px circle at 85% 10%, rgba(34, 211, 238, 0.12), transparent 45%),
        radial-gradient(600px circle at 50% 100%, rgba(16, 185, 129, 0.10), transparent 50%);
    pointer-events: none;
}
.glass {
    border-radius: 1rem;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(12px);
    box-shadow: 0 8px 30px -12px rgba(0, 0, 0, 0.6);
}
.lbl { display: block; font-size: 0.7rem; font-weight: 500; color: #94a3b8; margin-bottom: 0.25rem; }
.inp {
    width: 100%;
    border-radius: 0.5rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(2, 6, 23, 0.6);
    color: #e2e8f0;
    padding: 0.45rem 0.6rem;
    font-size: 0.8rem;
}
.inp:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.3); }
.btn-primary {
    width: 100%;
    border-radius: 0.5rem;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: #fff;
    padding: 0.55rem 1rem;
    font-size: 0.85rem;
    font-weight: 600;
    transition: filter 0.2s, transform 0.1s;
}
.btn-primary:hover:not(:disabled) { filter: brightness(1.1); }
.btn-primary:active:not(:disabled) { transform: translateY(1px); }
.btn-primary:disabled { opacity: 0.5; }

.ooda-badge {
    width: 48px; height: 48px; border-radius: 9999px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.6rem; font-weight: 700; letter-spacing: 0.05em; color: #c7d2fe;
    background: rgba(2, 6, 23, 0.8);
    border: 1px solid rgba(99, 102, 241, 0.4);
    position: relative;
}
.ooda-badge::before {
    content: ''; position: absolute; inset: -3px; border-radius: 9999px;
    background: conic-gradient(from 0deg, transparent, #6366f1, #22d3ee, transparent 70%);
    animation: spin 4s linear infinite; z-index: -1; filter: blur(2px);
}
@keyframes spin { to { transform: rotate(360deg); } }

/* event row 入場 */
.row-enter-from { opacity: 0; transform: translateY(-6px); }
.row-enter-active { transition: all 0.4s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
</style>
