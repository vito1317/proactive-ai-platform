<script setup>
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PipelineFlow from '../Components/PipelineFlow.vue';
import UiIcon from '../Components/UiIcon.vue';

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

/* ---------- 時鐘（狀態列） ---------- */
const clock = ref('');
let clockTimer = null;
function tickClock() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    clock.value = `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

/* ---------- 流程圖 stages（由 stats 推導） ---------- */
const s = (k) => props.stats[k] ?? 0;
const stages = computed(() => {
    const total = s('total');
    const oriented = s('normalized') + s('routed');
    const decided = s('routed');
    return [
        { key: 'l1', label: 'L1 感知', sub: 'OBSERVE', icon: 'radar', accent: '#4cc2e6', count: total, active: total > 0 },
        { key: 'l2', label: 'L2 記憶', sub: 'ORIENT', icon: 'database', accent: '#45cfc0', count: oriented, active: oriented > 0 },
        { key: 'l3', label: 'L3 認知', sub: 'DECIDE', icon: 'cpu', accent: '#3fdc97', count: decided, active: decided > 0 },
        { key: 'l4', label: 'L4 行動', sub: 'ACT', icon: 'zap', accent: '#e6b450', count: s('acted'), active: s('acted') > 0 },
        { key: 'l5', label: 'L5 護欄', sub: 'GUARDRAIL', icon: 'shield', accent: '#f06a6a', count: s('hitl'), active: s('hitl') > 0 },
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
    tickClock();
    clockTimer = setInterval(tickClock, 1000);
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
    clearInterval(clockTimer);
});

/* ---------- 樣式 ---------- */
const severityClass = (x) => ({
    low: 'tag--dim', medium: 'tag--amber',
    high: 'tag--amber', critical: 'tag--red',
}[x] || 'tag--dim');
const statusClass = (x) => ({
    received: 'tag--dim', normalized: 'tag--cyan',
    routed: 'tag--green', ignored: 'tag--faint',
    failed: 'tag--red',
}[x] || 'tag--dim');
const autonomyClass = (x) => ({
    copilot: 'tag--dim', supervisor: 'tag--cyan',
    autopilot: 'tag--green',
}[x] || 'tag--dim');
const runStatusClass = (x) => ({
    running: 'tag--cyan', awaiting_hitl: 'tag--amber',
    completed: 'tag--green', failed: 'tag--red',
}[x] || 'tag--dim');
const runStatusLabel = (x) => ({
    running: '思考中…', awaiting_hitl: '待人類核准', completed: '已完成', failed: '失敗',
}[x] || x);
const actionStatusClass = (x) => ({
    executed: 'tag--green', awaiting_approval: 'tag--amber',
    rejected: 'tag--red', proposed: 'tag--dim',
    suggested: 'tag--cyan', observed: 'tag--faint',
}[x] || 'tag--dim');
</script>

<template>
    <Head title="中控台" />

    <div class="console">
        <div class="relative z-10 mx-auto max-w-7xl px-4 py-4 sm:px-6">
            <!-- ░ 頂部狀態列 ░ -->
            <header class="statusbar glass mb-5">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
                    <!-- 標識 -->
                    <div class="flex items-center gap-3">
                        <div class="sigil" aria-hidden="true">
                            <span class="sigil-block"></span>
                        </div>
                        <div class="leading-tight">
                            <h1 class="font-mono text-sm font-bold tracking-[0.18em] text-white">
                                {{ platform }} <span class="text-(--ops-green)">//</span> OPS CONSOLE
                            </h1>
                            <p class="font-mono text-[10px] tracking-[0.12em] text-(--ops-ink-faint)">PROACTIVE AGENT · OBSERVE → ORIENT → DECIDE → ACT</p>
                        </div>
                    </div>

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <!-- LIVE + 時鐘 -->
                        <div class="hidden items-center gap-2 border border-(--ops-line) bg-black/30 px-2.5 py-1 font-mono text-[11px] sm:flex">
                            <span class="relative flex h-1.5 w-1.5">
                                <span class="absolute inline-flex h-full w-full animate-ping bg-(--ops-green) opacity-60"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 bg-(--ops-green)"></span>
                            </span>
                            <span class="tracking-[0.14em] text-(--ops-green)">LIVE</span>
                            <span class="text-(--ops-ink-faint)">|</span>
                            <span class="tabular-nums tracking-widest text-(--ops-ink-dim)">{{ clock }}</span>
                        </div>

                        <!-- 通知 -->
                        <div class="relative">
                            <button class="navlink relative" title="通知" @click="showBell = !showBell">
                                <UiIcon name="bell" :size="13" />
                                <span v-if="auth.unread" class="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 items-center justify-center bg-(--ops-red) px-1 font-mono text-[9px] font-bold text-black">{{ auth.unread }}</span>
                            </button>
                            <div v-if="showBell" class="glass absolute right-0 z-30 mt-2 w-80 p-2">
                                <div class="flex items-center justify-between px-2 py-1">
                                    <span class="font-mono text-[10px] uppercase tracking-[0.14em] text-(--ops-ink-dim)">通知</span>
                                    <button v-if="auth.unread" class="cursor-pointer font-mono text-[10px] text-(--ops-green) hover:brightness-125" @click="markRead">全部標記已讀</button>
                                </div>
                                <div v-if="!auth.notifications?.length" class="px-2 py-4 text-center font-mono text-xs text-(--ops-ink-faint)">NO SIGNAL</div>
                                <div v-for="n in auth.notifications" :key="n.id" class="border-t border-(--ops-line) px-2 py-2 text-xs" :class="n.read ? 'text-(--ops-ink-faint)' : 'text-(--ops-ink)'">
                                    <span v-if="!n.read" class="mr-1 text-(--ops-amber)">●</span>{{ n.message }}
                                    <div class="font-mono text-[10px] text-(--ops-ink-faint)">{{ n.at }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- 導覽 -->
                        <nav class="flex max-w-full items-center gap-1.5 overflow-x-auto">
                            <Link href="/voice" class="navlink"><UiIcon name="mic" :size="12" /> 語音</Link>
                            <Link href="/chat" class="navlink"><UiIcon name="message" :size="12" /> 對話</Link>
                            <Link href="/automations" class="navlink"><UiIcon name="workflow" :size="12" /> 自動化</Link>
                            <Link href="/packs" class="navlink"><UiIcon name="package" :size="12" /> 領域包</Link>
                            <Link href="/settings" class="navlink"><UiIcon name="sliders" :size="12" /> 設定</Link>
                            <button class="navlink cursor-pointer" :title="auth.user?.email" @click="logout"><UiIcon name="logout" :size="12" /> 登出</button>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- flash -->
            <transition name="fade">
                <div v-if="flash.success" class="mb-5 border border-(--ops-green)/40 bg-(--ops-green-dim) px-4 py-3 font-mono text-sm text-(--ops-green)">
                    <span class="mr-2 font-bold">OK</span>{{ flash.success }}
                </div>
            </transition>

            <!-- ░ 流程動畫圖 ░ -->
            <section class="glass corners mb-5 p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="panel-title">Agentic Pipeline · 即時流向</h2>
                    <span class="font-mono text-[10px] tracking-wider text-(--ops-ink-faint)">事件沿五層流動 · 光點密度反映吞吐</span>
                </div>
                <PipelineFlow :stages="stages" :intensity="intensity" />
            </section>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <!-- 指令 + 領域 -->
                <section class="space-y-5 lg:col-span-1">
                    <div class="glass corners p-5">
                        <h2 class="panel-title">
                            指揮 AI
                            <span class="tag tag--green ml-1 normal-case tracking-normal"><UiIcon name="wand" :size="10" /> 自動判斷</span>
                        </h2>
                        <p class="mt-2 text-xs leading-relaxed text-(--ops-ink-dim)">
                            用白話說一句話就好——AI 會自動判斷要「執行任務、新增領域、還是設定通知」並處理。
                        </p>
                        <form class="mt-3 space-y-3" @submit.prevent="submitPanel()">
                            <textarea
                                v-model="askForm.message"
                                rows="3"
                                placeholder="> 主機中毒幫我處理 / 監控資料庫慢查詢 / 設定我的 Telegram 通知…"
                                class="inp"
                                @keydown.enter="onEnter"
                            ></textarea>
                            <button type="submit" :disabled="askForm.processing || !askForm.message.trim()" class="btn-primary flex items-center justify-center gap-2">
                                <template v-if="askForm.processing">ANALYZING<span class="typing-cursor">_</span></template>
                                <template v-else><UiIcon name="send" :size="13" /> EXECUTE · 交給 AI 處理</template>
                            </button>
                        </form>
                        <div class="mt-3">
                            <div class="mb-1.5 font-mono text-[10px] uppercase tracking-[0.14em] text-(--ops-ink-faint)">試試看</div>
                            <div class="flex flex-col gap-1.5">
                                <button
                                    v-for="(ex, i) in examples"
                                    :key="i"
                                    class="cursor-pointer border border-(--ops-line) bg-black/20 px-3 py-1.5 text-left text-xs text-(--ops-ink-dim) transition-colors hover:border-(--ops-green)/40 hover:text-(--ops-ink)"
                                    @click="fillExample(ex)"
                                ><span class="mr-1.5 font-mono text-(--ops-green)">›</span>{{ ex }}</button>
                            </div>
                        </div>
                    </div>

                    <div class="glass p-5">
                        <h2 class="panel-title">已載入領域</h2>
                        <ul class="mt-3 space-y-2">
                            <li v-for="d in domains" :key="d.domain" class="border border-(--ops-line) bg-black/20 px-3 py-2">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono text-sm text-(--ops-cyan)">{{ d.domain }}</span>
                                    <span class="tag" :class="autonomyClass(d.autonomy)">{{ d.autonomy }}</span>
                                </div>
                                <p class="mt-1 text-xs text-(--ops-ink-dim)">{{ d.description }}</p>
                                <p class="mt-1 font-mono text-[10px] tracking-wide text-(--ops-ink-faint)">{{ d.agents.length }} AGENTS · {{ d.events.length }} EVENTS · {{ d.high_risk_tools.length }} HIGH-RISK</p>
                            </li>
                        </ul>
                    </div>

                    <!-- 節點 / Gateway 連線狀態 -->
                    <div class="glass p-5">
                        <div class="flex items-center justify-between">
                            <h2 class="panel-title"><UiIcon name="radio" :size="12" /> 節點連線狀態</h2>
                            <button class="btn-ghost flex cursor-pointer items-center gap-1.5" @click="fetchNodes"><UiIcon name="refresh" :size="11" /> {{ nodesLoading ? 'SCAN…' : 'RESCAN' }}</button>
                        </div>
                        <p v-if="!nodes.length && !nodesLoading" class="mt-2 text-xs text-(--ops-ink-faint)">尚無節點。用下方指令在其他機器接上 Gateway。</p>
                        <ul class="mt-3 space-y-1.5">
                            <li v-for="n in nodes" :key="n.name" class="flex items-center gap-2.5 border border-(--ops-line) bg-black/20 px-3 py-2 text-sm">
                                <span class="node-dot" :class="n.ok ? 'node-dot--up' : 'node-dot--down'"></span>
                                <span class="font-mono text-xs font-medium text-white">{{ n.name }}</span>
                                <span class="ml-auto font-mono text-[10px] text-(--ops-ink-faint)">{{ n.ok ? (n.ms + 'ms · ' + (n.tools?.length || 0) + ' TOOLS') : (n.error || 'OFFLINE') }}</span>
                            </li>
                        </ul>
                    </div>

                    <!-- 排定的定時任務 -->
                    <div class="glass p-5">
                        <h2 class="panel-title"><UiIcon name="clock" :size="12" /> 排定的行程 / 定時任務</h2>
                        <ul v-if="scheduledTasks.length" class="mt-3 space-y-1.5">
                            <li v-for="t in scheduledTasks" :key="t.id" class="flex items-center justify-between gap-2 border border-(--ops-line) bg-black/20 px-3 py-2">
                                <div class="min-w-0">
                                    <div class="truncate text-sm text-(--ops-ink)">{{ t.command }}</div>
                                    <div class="font-mono text-[10px] text-(--ops-cyan)">{{ t.run_at }}<span v-if="t.recur === 'daily'" class="ml-1 text-(--ops-green)">· DAILY</span></div>
                                </div>
                                <button class="btn-ghost shrink-0 cursor-pointer hover:!text-(--ops-red)"
                                    @click="router.post('/console/scheduled/' + t.id + '/cancel', {}, { preserveScroll: true })">取消</button>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-xs text-(--ops-ink-faint)">目前沒有排定的定時任務。對 AI 說「每天早上八點報天氣」「明天三點提醒我開會」就會出現在這。</p>
                    </div>

                    <!-- #9 LLM 用量觀測（平台層級，僅 admin 顯示） -->
                    <div v-if="$page.props.auth?.user?.is_admin" class="glass p-5">
                        <h2 class="panel-title"><UiIcon name="barChart" :size="12" /> AI 用量（今日）</h2>
                        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                            <div class="stat">
                                <div class="stat-num">{{ llmUsage.today_calls ?? 0 }}</div>
                                <div class="stat-lbl">CALLS</div>
                            </div>
                            <div class="stat">
                                <div class="stat-num">{{ ((llmUsage.today_tokens ?? 0) / 1000).toFixed(1) }}k</div>
                                <div class="stat-lbl">TOKENS</div>
                            </div>
                            <div class="stat">
                                <div class="stat-num">{{ llmUsage.today_avg_ms ?? 0 }}<span class="text-xs">ms</span></div>
                                <div class="stat-lbl">AVG LATENCY</div>
                            </div>
                        </div>
                        <p class="mt-2 font-mono text-[10px] tracking-wide text-(--ops-ink-faint)">WEEK: {{ llmUsage.week_calls ?? 0 }} CALLS · {{ ((llmUsage.week_tokens ?? 0) / 1000).toFixed(0) }}k TOKENS</p>
                    </div>

                    <!-- 自我改進：AI 學會的做法 -->
                    <div class="glass p-5">
                        <h2 class="panel-title"><UiIcon name="cpu" :size="12" /> AI 學會的技能（自我改進）</h2>
                        <p class="mt-2 text-xs text-(--ops-ink-dim)">完成多步任務後，AI 會把成功的做法學起來，下次同類需求自動套用、更快更穩。</p>
                        <ul v-if="learnedSkills.length" class="mt-3 space-y-2">
                            <li v-for="sk in learnedSkills" :key="sk.id" class="border border-(--ops-line) bg-black/20 px-3 py-2">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-sm font-medium text-(--ops-cyan)">▸ {{ sk.name }}</span>
                                    <span class="shrink-0 font-mono text-[10px] text-(--ops-ink-faint)">×{{ sk.uses }}</span>
                                </div>
                                <p class="mt-0.5 text-[11px] text-(--ops-ink-dim)">{{ sk.when_to_use }}</p>
                                <p class="mt-1 whitespace-pre-wrap text-[11px] leading-relaxed text-(--ops-ink)">{{ sk.steps }}</p>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-xs text-(--ops-ink-faint)">尚未學會任何做法——叫 AI 完成幾個多步驟任務（排行程、傳訊息給多人…）就會開始累積。</p>

                        <h3 class="panel-title mt-5"><UiIcon name="bookmark" :size="12" /> 關於你的長期記憶</h3>
                        <ul v-if="userMemories.length" class="mt-2 flex flex-wrap gap-1.5">
                            <li v-for="m in userMemories" :key="m.id" class="border border-(--ops-line) bg-black/20 px-2.5 py-1 text-[11px] text-(--ops-ink-dim)">{{ m.content }}</li>
                        </ul>
                        <p v-else class="mt-2 text-xs text-(--ops-ink-faint)">還沒記住關於你的事。對 AI 說「記住我住汐止」之類就會記起來。</p>
                    </div>

                    <!-- 一鍵安裝 -->
                    <div class="glass p-5">
                        <h2 class="panel-title"><UiIcon name="download" :size="12" /> 一鍵安裝</h2>
                        <p class="mt-2 text-xs text-(--ops-ink-dim)">在新機器部署整套平台：</p>
                        <div class="mt-2 flex items-center gap-2">
                            <code class="flex-1 overflow-x-auto whitespace-nowrap border border-(--ops-line) bg-black/50 px-3 py-2 font-mono text-[11px] text-(--ops-green)">{{ installCommand }}</code>
                            <button class="btn-ghost shrink-0 cursor-pointer" @click="copyInstall">
                                {{ copied ? '✓ COPIED' : 'COPY' }}
                            </button>
                        </div>

                        <p class="mt-4 flex items-center gap-1.5 text-xs text-(--ops-ink-dim)"><UiIcon name="satellite" :size="12" /> 在其他節點 / Mac 自動接線（裝 gateway + cloudflared 通道 + 自動註冊，一行搞定）：</p>
                        <div class="mt-2 flex items-center gap-2">
                            <code class="flex-1 overflow-x-auto whitespace-nowrap border border-(--ops-line) bg-black/50 px-3 py-2 font-mono text-[11px] text-(--ops-cyan)">{{ gatewayInstallCommand }}</code>
                            <button class="btn-ghost shrink-0 cursor-pointer" @click="copyGateway">
                                {{ copiedGw ? '✓ COPIED' : 'COPY' }}
                            </button>
                        </div>
                        <p class="mt-1.5 text-[10px] leading-relaxed text-(--ops-ink-faint)">在目標機器（Mac/Linux）貼上執行：自動裝好、開 cloudflared 公網通道、註冊成本平台節點。之後說「在 &lt;主機名&gt; 上打開 chrome」即可。管理：<code class="text-(--ops-ink-dim)">./gw status|stop|port N</code></p>
                    </div>

                    <!-- Android 節點配對 -->
                    <div class="glass p-5">
                        <h2 class="panel-title"><UiIcon name="smartphone" :size="12" /> Android 節點</h2>
                        <p class="mt-2 text-xs text-(--ops-ink-dim)">把手機變成節點：下載 App → 掃描下方 QR → 一鍵配對串接。</p>
                        <div class="mt-3 flex flex-col items-center gap-3 sm:flex-row sm:items-start">
                            <img v-if="pair.qr" :src="pair.qr" alt="配對 QR" class="h-40 w-40 shrink-0 border border-(--ops-line) bg-white p-2" />
                            <div v-else class="flex h-40 w-40 shrink-0 items-center justify-center border border-(--ops-line) bg-black/40 text-xs text-(--ops-ink-faint)">
                                <button class="btn-ghost flex cursor-pointer items-center gap-1.5" @click="loadPair"><UiIcon name="qr" :size="12" /> 產生配對 QR</button>
                            </div>
                            <div class="min-w-0 flex-1 space-y-2">
                                <a :href="apkUrl" target="_blank" class="flex items-center justify-center gap-2 border border-(--ops-green)/40 bg-(--ops-green-dim) px-3 py-2 text-center font-mono text-xs text-(--ops-green) transition-colors hover:bg-(--ops-green)/25">
                                    <UiIcon name="download" :size="13" /> 下載 Android App（APK）
                                </a>
                                <p class="text-[11px] text-(--ops-ink-dim)">App 內「節點」分頁 →「掃描 QR 一鍵配對」掃上面的 QR 即可。</p>
                                <button v-if="pair.code" class="btn-ghost w-full cursor-pointer" @click="copyPair">
                                    {{ copiedPair ? '✓ 已複製配對碼' : '複製配對碼（手動貼上用）' }}
                                </button>
                                <p class="font-mono text-[10px] text-(--ops-amber)/90">⚠ 配對碼含註冊金鑰，請勿外流。</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 事件流 -->
                <section class="lg:col-span-2">
                    <div class="glass corners overflow-hidden">
                        <div class="flex items-center justify-between border-b border-(--ops-line) px-5 py-3">
                            <h2 class="panel-title">事件流 · OBSERVE → ORIENT → ROUTE</h2>
                            <span class="font-mono text-[10px] tracking-wider text-(--ops-ink-faint)">LATEST {{ events.length }}</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-(--ops-line) font-mono text-[10px] uppercase tracking-[0.14em] text-(--ops-ink-faint)">
                                        <th class="px-4 py-2 font-medium">#</th><th class="px-4 py-2 font-medium">主題 / 來源</th>
                                        <th class="px-4 py-2 font-medium">意圖</th><th class="px-4 py-2 font-medium">嚴重性</th>
                                        <th class="px-4 py-2 font-medium">領域</th><th class="px-4 py-2 font-medium">狀態</th><th class="px-4 py-2 font-medium">時間</th>
                                    </tr>
                                </thead>
                                <TransitionGroup tag="tbody" name="row">
                                    <tr v-for="e in events" :key="e.id" class="border-b border-(--ops-line)/60 transition-colors hover:bg-(--ops-green)/4">
                                        <td class="px-4 py-2 font-mono text-xs tabular-nums text-(--ops-ink-faint)">{{ e.id }}</td>
                                        <td class="px-4 py-2"><div class="font-mono text-xs text-(--ops-ink)">{{ e.topic }}</div><div class="font-mono text-[10px] text-(--ops-ink-faint)">{{ e.source }}</div></td>
                                        <td class="px-4 py-2 text-xs text-(--ops-ink-dim)">{{ e.intent ?? '—' }}</td>
                                        <td class="px-4 py-2"><span v-if="e.severity" class="tag" :class="severityClass(e.severity)">{{ e.severity }}</span><span v-else class="text-(--ops-ink-faint)">—</span></td>
                                        <td class="px-4 py-2 font-mono text-xs text-(--ops-ink-dim)">{{ e.domain ?? '—' }}</td>
                                        <td class="px-4 py-2"><span class="tag" :class="statusClass(e.status)">{{ e.status }}</span></td>
                                        <td class="px-4 py-2 font-mono text-[10px] text-(--ops-ink-faint)">{{ e.at }}</td>
                                    </tr>
                                    <tr v-if="!events.length" key="empty">
                                        <td colspan="7" class="px-4 py-12 text-center font-mono text-xs text-(--ops-ink-faint)">NO EVENTS — 用左側「指揮 AI」注入一筆，或 POST /webhooks/{source}</td>
                                    </tr>
                                </TransitionGroup>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <!-- ░ AI 運行 / 思考軌跡 ░ -->
            <section class="glass corners mt-5 overflow-hidden">
                <div class="flex items-center justify-between border-b border-(--ops-line) px-5 py-3">
                    <h2 class="panel-title">AI 認知運行 · DECIDE → ACT → GUARDRAIL</h2>
                    <span class="font-mono text-[10px] tracking-wider text-(--ops-ink-faint)">{{ runs.length }} RUNS</span>
                </div>

                <!-- AI 核心終端機狀態 -->
                <div class="border-b border-(--ops-line) bg-black/40 px-5 py-3 font-mono text-xs">
                    <div v-if="activeRun" class="flex items-center gap-3 text-(--ops-green)">
                        <span class="flex h-2 w-2 items-center justify-center">
                            <span class="absolute inline-flex h-2 w-2 animate-ping bg-(--ops-green) opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 bg-(--ops-green)"></span>
                        </span>
                        <div class="flex flex-col">
                            <span class="font-bold tracking-[0.1em]">SYSTEM_ACTIVE // 執行中</span>
                            <span class="mt-1 text-(--ops-ink-dim)">
                                &gt; [{{ activeRun.goal }}] <span class="text-(--ops-green)">{{ currentThinkingStep }}</span><span class="typing-cursor">_</span>
                            </span>
                        </div>
                    </div>
                    <div v-else class="flex items-center gap-3 text-(--ops-ink-faint)">
                        <span class="h-2 w-2 bg-(--ops-line-strong)"></span>
                        <span class="tracking-[0.1em]">SYSTEM_IDLE // 待機中</span>
                    </div>
                </div>

                <div v-if="!runs.length" class="px-5 py-10 text-center font-mono text-xs text-(--ops-ink-faint)">
                    NO RUNS — 送出指令後，協調者會在這裡展開 ReAct 推理
                </div>

                <div v-for="r in runs" :key="r.id" class="border-b border-(--ops-line)/60">
                    <button class="flex w-full cursor-pointer items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-white/3" @click="toggle(r.id)">
                        <span class="font-mono text-xs text-(--ops-cyan)">{{ r.coordinator }}</span>
                        <span class="text-xs text-(--ops-ink-faint)">←</span>
                        <span class="font-mono text-xs text-(--ops-ink-dim)">{{ r.topic }}</span>
                        <span class="tag" :class="runStatusClass(r.status)">
                            <span v-if="r.status === 'running'" class="inline-block animate-pulse">●</span>{{ runStatusLabel(r.status) }}
                        </span>
                        <span class="ml-auto flex items-center gap-3 font-mono text-[10px] tracking-wide text-(--ops-ink-faint)">
                            <span>{{ r.findings?.length || 0 }} 發現</span>
                            <span>{{ r.actions?.length || 0 }} 動作</span>
                            <span>{{ r.tokens }} TOK</span>
                            <UiIcon :name="expanded.has(r.id) ? 'chevronUp' : 'chevronDown'" :size="12" />
                        </span>
                    </button>

                    <div v-if="r.actions?.length" class="space-y-1 px-5 pb-3">
                        <div v-for="(a, i) in r.actions" :key="i" class="flex flex-wrap items-center gap-2">
                            <span class="tag !py-1" :class="actionStatusClass(a.status)">
                                <UiIcon name="zap" :size="10" /> {{ a.action }}
                                <span class="opacity-70">· {{ a.status === 'awaiting_approval' ? '待核准' : a.status === 'executed' ? '已執行' : a.status === 'rejected' ? '已駁回' : a.status === 'suggested' ? '建議（未執行）' : a.status === 'observed' ? '僅記錄' : a.status }}</span>
                                <template v-if="a.status === 'awaiting_approval'">
                                    <button class="ml-1 cursor-pointer border border-(--ops-green)/50 bg-(--ops-green-dim) px-1.5 py-0.5 text-(--ops-green) transition-colors hover:bg-(--ops-green)/30" title="核准並執行" @click.stop="decide(r.id, i, 'approve')">✓ 核准</button>
                                    <button class="cursor-pointer border border-(--ops-red)/50 bg-(--ops-red)/15 px-1.5 py-0.5 text-(--ops-red) transition-colors hover:bg-(--ops-red)/30" title="駁回" @click.stop="decide(r.id, i, 'reject')">✗ 駁回</button>
                                </template>
                            </span>
                            <span v-if="a.result" class="font-mono text-xs text-(--ops-green)/70">→ {{ a.result }}</span>
                        </div>
                    </div>

                    <div v-if="expanded.has(r.id)" class="space-y-4 bg-black/30 px-5 py-4">
                        <div v-if="r.summary" class="border border-(--ops-line) bg-black/20 px-3 py-2 text-sm text-(--ops-ink-dim)">
                            <span class="font-mono text-[10px] uppercase tracking-[0.14em] text-(--ops-ink-faint)">總結 · </span>{{ r.summary }}
                        </div>
                        <div v-if="r.findings?.length">
                            <div class="mb-1 font-mono text-[10px] uppercase tracking-[0.14em] text-(--ops-ink-faint)">發現</div>
                            <ul class="space-y-1">
                                <li v-for="(f, i) in r.findings" :key="i" class="text-sm text-(--ops-ink-dim)"><span class="mr-1.5 text-(--ops-green)">▪</span>{{ f }}</li>
                            </ul>
                        </div>
                        <div>
                            <div class="mb-2 font-mono text-[10px] uppercase tracking-[0.14em] text-(--ops-ink-faint)">ReAct 軌跡</div>
                            <ol class="space-y-3">
                                <li v-for="(st, i) in r.steps" :key="i" class="border-l border-(--ops-green)/30 pl-4">
                                    <div class="flex items-center gap-2">
                                        <span class="border border-(--ops-cyan)/30 bg-(--ops-cyan)/10 px-2 py-0.5 font-mono text-xs text-(--ops-cyan)">{{ st.action }}</span>
                                        <span v-if="st.action_input && Object.keys(st.action_input).length" class="font-mono text-[10px] text-(--ops-ink-faint)">{{ JSON.stringify(st.action_input) }}</span>
                                    </div>
                                    <p v-if="st.thought" class="mt-1 flex items-start gap-1.5 text-xs text-(--ops-ink-dim)"><UiIcon name="cpu" :size="11" class="mt-0.5 shrink-0" /> {{ st.thought }}</p>
                                    <p v-if="st.observation" class="mt-1 flex items-start gap-1.5 text-xs text-(--ops-green)/80"><UiIcon name="eye" :size="11" class="mt-0.5 shrink-0" /> {{ st.observation }}</p>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 頁腳刻度 -->
            <footer class="mt-6 flex items-center justify-between border-t border-(--ops-line) pt-3 font-mono text-[10px] tracking-[0.14em] text-(--ops-ink-faint)">
                <span>{{ platform }} OPS CONSOLE</span>
                <span>POLL 4000ms · GRID 48px · SIG {{ stats.total ?? 0 }}</span>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.console {
    position: relative;
    min-height: 100vh;
    color: var(--ops-ink);
}

/* ---------- 頂部狀態列 ---------- */
.statusbar {
    border-radius: 0;
}
.sigil {
    width: 30px;
    height: 30px;
    border: 1px solid var(--ops-green);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.sigil::before,
.sigil::after {
    content: '';
    position: absolute;
    width: 5px;
    height: 5px;
}
.sigil::before { top: -3px; left: -3px; border-top: 1px solid var(--ops-green); border-left: 1px solid var(--ops-green); }
.sigil::after { bottom: -3px; right: -3px; border-bottom: 1px solid var(--ops-green); border-right: 1px solid var(--ops-green); }
.sigil-block {
    width: 10px;
    height: 10px;
    background: var(--ops-green);
    box-shadow: 0 0 10px rgba(63, 220, 151, 0.8);
    animation: sigil-pulse 3s ease-in-out infinite;
}
@keyframes sigil-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.45; }
}

/* 導覽連結 */
.navlink {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 1px solid var(--ops-line);
    background: transparent;
    padding: 0.3rem 0.6rem;
    font-family: var(--font-mono);
    font-size: 0.68rem;
    letter-spacing: 0.06em;
    color: var(--ops-ink-dim);
    white-space: nowrap;
    flex: 0 0 auto;
    transition: color 0.15s, border-color 0.15s, background 0.15s;
}
.navlink:hover {
    color: var(--ops-green);
    border-color: rgba(63, 220, 151, 0.4);
    background: rgba(63, 220, 151, 0.06);
}

/* ---------- 節點狀態燈 ---------- */
.node-dot {
    width: 7px;
    height: 7px;
    flex: 0 0 auto;
}
.node-dot--up {
    background: var(--ops-green);
    box-shadow: 0 0 8px rgba(63, 220, 151, 0.8);
}
.node-dot--down {
    background: var(--ops-red);
    box-shadow: 0 0 8px rgba(240, 106, 106, 0.6);
}

/* ---------- 統計磚 ---------- */
.stat {
    border: 1px solid var(--ops-line);
    background: rgba(0, 0, 0, 0.25);
    padding: 0.7rem 0.25rem;
}
.stat-num {
    font-family: var(--font-mono);
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--ops-green);
    font-variant-numeric: tabular-nums;
}
.stat-lbl {
    margin-top: 0.15rem;
    font-family: var(--font-mono);
    font-size: 0.58rem;
    letter-spacing: 0.14em;
    color: var(--ops-ink-faint);
}

/* ---------- 徽章色票 ---------- */
.tag--green { color: var(--ops-green); background: rgba(63, 220, 151, 0.1); border-color: rgba(63, 220, 151, 0.35); }
.tag--cyan { color: var(--ops-cyan); background: rgba(76, 194, 230, 0.1); border-color: rgba(76, 194, 230, 0.35); }
.tag--amber { color: var(--ops-amber); background: rgba(230, 180, 80, 0.1); border-color: rgba(230, 180, 80, 0.35); }
.tag--red { color: var(--ops-red); background: rgba(240, 106, 106, 0.12); border-color: rgba(240, 106, 106, 0.4); }
.tag--dim { color: var(--ops-ink-dim); background: rgba(255, 255, 255, 0.04); border-color: var(--ops-line); }
.tag--faint { color: var(--ops-ink-faint); background: transparent; border-color: var(--ops-line); }

/* ---------- 打字游標 ---------- */
@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}
.typing-cursor {
    animation: blink 1s step-end infinite;
    font-weight: bold;
    color: var(--ops-green);
}

/* event row 入場 */
.row-enter-from { opacity: 0; transform: translateY(-6px); }
.row-enter-active { transition: all 0.35s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
</style>
