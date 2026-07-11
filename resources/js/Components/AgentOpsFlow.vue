<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import UiIcon, { ICONS } from './UiIcon.vue';

/**
 * AGENT OPS · 即時作業流程動畫圖
 * SVG 網路圖：PAI CORE → 貝茲曲線（資料粒子沿線流動）→ 每個運行中 agent → ReAct 步驟鏈。
 * 當前節點脈衝發光、鏈尾游標節點表示推理中；守望有掃描光束、通話有聲紋。
 * 資料源：/console/agent-ops（協調者 ReAct、視覺守望、AI 外撥電話），每 3 秒輪詢。
 * 圖下方保留每個 agent 的完整細節卡（action_input / 思考 / 觀察 / 逐字稿）。
 */

const agents = ref([]);
const lastSync = ref('');
let timer = null;
let tickTimer = null;
const nowTick = ref(Date.now()); // 每秒跳動，讓 elapsed 即時累加

async function fetchOps() {
    try {
        const r = await fetch('/console/agent-ops', { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
        if (!r.ok) return;
        const d = await r.json();
        const at = Date.now();
        agents.value = (d.agents || []).map(a => ({ ...a, _syncAt: at }));
        lastSync.value = new Date().toLocaleTimeString('en-GB');
    } catch (e) { /* ignore（斷線時保留舊畫面） */ }
}

onMounted(() => {
    fetchOps();
    timer = setInterval(fetchOps, 3000);
    tickTimer = setInterval(() => { nowTick.value = Date.now(); }, 1000);
});
onUnmounted(() => { clearInterval(timer); clearInterval(tickTimer); });

/* ---------- 工具 → 視覺分類（icon / 中文動作 / 色票） ---------- */
const C = { green: '#3fdc97', cyan: '#4cc2e6', amber: '#e6b450', red: '#f06a6a', dim: '#8b98a5' };
const CATEGORIES = [
    { re: /(browse|web|url|http|fetch|crawl|surf|page)/i, icon: 'globe', label: '瀏覽網頁', color: C.cyan },
    { re: /(gui|tap|click|swipe|screenshot|screen_|type|keyboard|open_app|adb|device|android|mobile|control)/i, icon: 'smartphone', label: '操作裝置', color: C.green },
    { re: /(recall|memory|remember)/i, icon: 'database', label: '記憶檢索', color: C.cyan },
    { re: /\b(call|dial|twilio|outbound)/i, icon: 'phone', label: '撥打電話', color: C.amber },
    { re: /(watch|vision|look|see|observe_screen|image)/i, icon: 'eye', label: '視覺判讀', color: C.cyan },
    { re: /(notify|message|mail|send|telegram|line|slack|discord)/i, icon: 'send', label: '發送訊息', color: C.green },
    { re: /(log|read_|file|repo)/i, icon: 'file', label: '讀取資料', color: C.dim },
    { re: /(query|lookup|match|search|list)/i, icon: 'search', label: '查詢比對', color: C.cyan },
    { re: /(propose|record_finding|plan|ticket)/i, icon: 'workflow', label: '規劃提案', color: C.amber },
    { re: /(handoff)/i, icon: 'shuffle', label: '移交領域', color: C.amber },
    { re: /(finish|reflect|summar)/i, icon: 'flag', label: '收尾反思', color: C.green },
    { re: /(get_event_context|context)/i, icon: 'radar', label: '讀取情境', color: C.dim },
];
function catOf(action) {
    const hit = CATEGORIES.find(c => c.re.test(action || ''));
    return hit || { icon: 'terminal', label: '工具呼叫', color: C.dim };
}

/* 對話 agent 的步驟是中文即時說明（SkillRunner stepLabel）→ 用中文/emoji 關鍵字分類 */
const ZH_CATEGORIES = [
    { re: /⚠️|失敗|錯誤/, icon: 'x', label: '執行失敗', color: C.red },
    { re: /✅|完成/, icon: 'check', label: '步驟完成', color: C.green },
    { re: /🛑|已停止|中止/, icon: 'x', label: '已停止', color: C.red },
    { re: /意圖|💠/, icon: 'radar', label: '解碼意圖', color: C.dim },
    { re: /盯|守望|👁|判讀|畫面變化/, icon: 'eye', label: '視覺判讀', color: C.cyan },
    { re: /搜尋|🔍|查詢|查一下|上網/, icon: 'search', label: '搜尋查詢', color: C.cyan },
    { re: /網頁|網址|🌐|瀏覽/, icon: 'globe', label: '瀏覽網頁', color: C.cyan },
    { re: /手機|截圖|點擊|滑動|📱|🚀|啟動|相機|拍照|操作|App|開啟/i, icon: 'smartphone', label: '操作裝置', color: C.green },
    { re: /電話|撥|訂位|📞/, icon: 'phone', label: '撥打電話', color: C.amber },
    { re: /檔案|📄|✏️/, icon: 'file', label: '讀寫檔案', color: C.dim },
    { re: /終端機|💻|🧬|指令/, icon: 'terminal', label: '執行指令', color: C.green },
    { re: /訊息|傳|寄|郵件|通知|📨|🔔|✉️/, icon: 'send', label: '發送訊息', color: C.green },
    { re: /行程|日曆|提醒|鬧鐘|⏰|📅|排程|等待|⏳/, icon: 'calendar', label: '行程排程', color: C.cyan },
    { re: /記憶|🧠|記住/, icon: 'database', label: '記憶', color: C.cyan },
    { re: /待辦|📋|規劃/, icon: 'workflow', label: '規劃待辦', color: C.amber },
    { re: /🤔|思考/, icon: 'cpu', label: '思考', color: C.dim },
    { re: /🔌|MCP|工具/, icon: 'workflow', label: '串接工具', color: C.amber },
];
function catOfText(t) {
    const hit = ZH_CATEGORIES.find(c => c.re.test(t || ''));
    return hit || { icon: 'terminal', label: '處理中', color: C.dim };
}

/* ---------- 顯示輔助 ---------- */
function fmtElapsed(a) {
    if (a.elapsed == null) return '';
    const extra = a._syncAt ? Math.floor((nowTick.value - a._syncAt) / 1000) : 0;
    const s = a.elapsed + Math.max(0, extra);
    const m = Math.floor(s / 60), r = s % 60;
    return m > 0 ? `${m}m${String(r).padStart(2, '0')}s` : `${r}s`;
}
function fmtInput(input) {
    if (!input || !Object.keys(input).length) return '';
    try {
        const s = JSON.stringify(input);
        return s.length > 160 ? s.slice(0, 160) + '…' : s;
    } catch { return ''; }
}
const trunc = (s, n) => (s && s.length > n ? s.slice(0, n) + '…' : (s || ''));
function currentStep(a) {
    return a.steps?.length ? a.steps[a.steps.length - 1] : null;
}
const kindMeta = {
    coordinator: { icon: 'cpu', tag: 'COORDINATOR' },
    chat: { icon: 'message', tag: 'CHAT' },
    watch: { icon: 'eye', tag: 'WATCH' },
    call: { icon: 'phone', tag: 'CALL' },
};
const statusLabel = (s) => ({
    running: 'RUNNING', awaiting_hitl: 'AWAIT HITL', active: 'WATCHING',
    pending: 'DIALING', in_progress: 'ON CALL',
}[s] || String(s).toUpperCase());
const statusClass = (s) => ({
    running: 'st--green', awaiting_hitl: 'st--amber', active: 'st--cyan',
    pending: 'st--amber', in_progress: 'st--green',
}[s] || 'st--dim');
const count = computed(() => agents.value.length);

/* ---------- SVG 流程圖幾何 ---------- */
const G = { W: 1280, ROW: 118, PT: 30, PB: 22, coreX: 92, agentX: 300, agentW: 180, agentH: 46, stepX0: 486, stepDX: 118, node: 40, maxSteps: 5 };
const H = computed(() => Math.max(236, G.PT + G.PB + agents.value.length * G.ROW));

function agentColor(a) {
    if (a.kind === 'watch') return C.cyan;
    if (a.kind === 'call') return a.status === 'in_progress' ? C.green : C.amber;
    if (a.kind === 'chat') return C.cyan;
    return a.status === 'awaiting_hitl' ? C.amber : C.green;
}

const rows = computed(() => {
    const cy = H.value / 2;
    return agents.value.map((a, i) => {
        const y = G.PT + i * G.ROW + G.ROW / 2;
        // chat 的步驟是中文即時說明（✅ 結果行不進鏈，細節卡才看得到）；coordinator 是 ReAct action
        const all = a.kind === 'coordinator' ? (a.steps || [])
            : a.kind === 'chat' ? (a.steps || []).filter(s => !/^✅/.test(s.text || ''))
            : [];
        const shown = all.slice(-G.maxSteps);
        const hidden = all.length - shown.length;
        const nodes = shown.map((s, j) => ({
            x: G.stepX0 + j * G.stepDX,
            cat: a.kind === 'chat' ? catOfText(s.text) : catOf(s.action),
            s,
        }));
        const allLen = all.length;
        const running = a.status === 'running';
        const live = ['running', 'in_progress', 'active', 'pending'].includes(a.status);
        const agentL = G.agentX - G.agentW / 2, agentR = G.agentX + G.agentW / 2;
        // core → agent 的貝茲曲線（粒子沿此路徑流動）
        const edge = `M ${G.coreX + 50} ${cy} C ${G.coreX + 170} ${cy}, ${agentL - 130} ${y}, ${agentL - 8} ${y}`;
        const cursorX = G.stepX0 + shown.length * G.stepDX; // 「推理下一步」游標節點
        return { a, i, y, nodes, hidden, allLen, running, live, edge, agentL, agentR, cursorX, color: agentColor(a) };
    });
});
</script>

<template>
    <section class="glass corners overflow-hidden">
        <div class="flex items-center justify-between border-b border-(--ops-line) px-5 py-3">
            <h2 class="panel-title">Agent Ops · 即時作業流</h2>
            <span class="font-mono text-[10px] tracking-wider text-(--ops-ink-faint)">
                <span :class="count ? 'text-(--ops-green)' : ''">{{ count }} ACTIVE</span>
                <span v-if="lastSync"> · SYNC {{ lastSync }}</span>
            </span>
        </div>

        <!-- 空狀態：雷達待機 -->
        <div v-if="!count" class="flex flex-col items-center gap-4 px-5 py-10">
            <div class="radar-idle" aria-hidden="true">
                <div class="radar-sweep"></div>
                <div class="radar-ring r1"></div>
                <div class="radar-ring r2"></div>
                <div class="radar-dot"></div>
            </div>
            <p class="font-mono text-xs tracking-[0.14em] text-(--ops-ink-faint)">NO ACTIVE AGENTS // 待機掃描中</p>
            <p class="text-xs text-(--ops-ink-faint)">交辦任務、開視覺守望、或叫 AI 打電話，這裡就會即時顯示每個 agent 在幹嘛。</p>
        </div>

        <!-- ░░ 動態流程圖 ░░ -->
        <div v-if="count" class="graph-wrap overflow-x-auto">
            <svg :viewBox="`0 0 ${G.W} ${H}`" class="graph" :style="{ minWidth: '1100px', width: '100%', height: 'auto' }" role="img" aria-label="運行中 agent 即時流程圖">
                <defs>
                    <filter id="ops-glow" x="-80%" y="-80%" width="260%" height="260%">
                        <feGaussianBlur stdDeviation="2.6" result="b" />
                        <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
                    </filter>
                    <pattern id="ops-grid" width="40" height="40" patternUnits="userSpaceOnUse">
                        <path d="M 40 0 L 0 0 0 40" fill="none" stroke="rgba(76,194,230,0.05)" stroke-width="1" />
                    </pattern>
                    <linearGradient id="ops-sweep" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0" stop-color="transparent" />
                        <stop offset="0.5" stop-color="rgba(63,220,151,0.05)" />
                        <stop offset="1" stop-color="transparent" />
                    </linearGradient>
                </defs>

                <!-- 藍圖網格 + 掃描光帶 -->
                <rect x="0" y="0" :width="G.W" :height="H" fill="url(#ops-grid)" />
                <rect class="sweep" y="0" width="150" :height="H" fill="url(#ops-sweep)" />

                <!-- ═══ 核心節點 ═══ -->
                <g :transform="`translate(${G.coreX}, ${H / 2})`">
                    <circle r="47" class="core-pulse" fill="none" stroke="rgba(63,220,151,0.35)" />
                    <circle r="40" fill="none" stroke="rgba(63,220,151,0.5)" stroke-dasharray="6 10" class="core-spin" />
                    <circle r="31" fill="#04080d" stroke="#1c2733" />
                    <circle r="31" fill="none" stroke="rgba(63,220,151,0.3)" filter="url(#ops-glow)" />
                    <text y="-2" class="t-core">PAI</text>
                    <text y="12" class="t-core-sub">CORE</text>
                </g>

                <template v-for="r in rows" :key="r.a.id">
                    <!-- ═══ core → agent 曲線 + 流動粒子 ═══ -->
                    <path :d="r.edge" fill="none" stroke="rgba(255,255,255,0.07)" stroke-width="1.5" />
                    <path :d="r.edge" fill="none" :stroke="r.color" stroke-opacity="0.5" stroke-width="1.5"
                          class="edge-flow" :class="{ 'edge-flow--fast': r.live }" />
                    <circle r="2.6" :fill="r.color" filter="url(#ops-glow)">
                        <animateMotion :dur="(r.live ? 1.6 : 3.2) + 's'" repeatCount="indefinite" :path="r.edge" />
                    </circle>
                    <circle r="1.8" :fill="r.color" opacity="0.7" filter="url(#ops-glow)">
                        <animateMotion :dur="(r.live ? 1.6 : 3.2) + 's'" begin="-0.8s" repeatCount="indefinite" :path="r.edge" />
                    </circle>

                    <!-- ═══ agent 節點 ═══ -->
                    <g :transform="`translate(${G.agentX}, ${r.y})`">
                        <rect :x="-G.agentW / 2" :y="-G.agentH / 2" :width="G.agentW" :height="G.agentH"
                              fill="rgba(4,8,13,0.92)" :stroke="r.color" stroke-opacity="0.55" class="agent-box" :class="{ 'agent-box--live': r.live }" :style="{ '--c': r.color }" />
                        <!-- 四角刻度 -->
                        <path :d="`M ${-G.agentW / 2} ${-G.agentH / 2 + 7} v -7 h 7 M ${G.agentW / 2} ${G.agentH / 2 - 7} v 7 h -7`" fill="none" :stroke="r.color" stroke-width="1.5" />
                        <g class="sicon" :style="{ color: r.color }" :transform="`translate(${-G.agentW / 2 + 10}, -8) scale(0.667)`">
                            <path v-for="(d, k) in ICONS[kindMeta[r.a.kind]?.icon || 'cpu']" :key="k" :d="d" />
                        </g>
                        <text :x="-G.agentW / 2 + 34" y="-3" class="t-name">{{ trunc(r.a.name, 20) }}</text>
                        <text :x="-G.agentW / 2 + 34" y="11" class="t-sub" :fill="r.color">{{ statusLabel(r.a.status) }}<tspan v-if="fmtElapsed(r.a)" fill="#56626e"> · T+{{ fmtElapsed(r.a) }}</tspan></text>
                        <circle :cx="G.agentW / 2 - 12" cy="0" r="3" :fill="r.color" class="blink" filter="url(#ops-glow)" />
                        <text x="0" :y="G.agentH / 2 + 16" text-anchor="middle" class="t-goal">{{ trunc(r.a.title, 30) }}</text>
                    </g>

                    <!-- ═══ coordinator / chat：步驟鏈 ═══ -->
                    <template v-if="r.a.kind === 'coordinator' || r.a.kind === 'chat'">
                        <!-- 省略的舊步驟 -->
                        <text v-if="r.hidden > 0" :x="G.stepX0 - G.stepDX / 2 - 14" :y="r.y - 12" class="t-hidden">⋯+{{ r.hidden }}</text>

                        <template v-for="(n, j) in r.nodes" :key="j">
                            <!-- 鏈接線 -->
                            <line :x1="j === 0 ? r.agentR + 4 : r.nodes[j - 1].x + G.node / 2 + 4" :y1="r.y"
                                  :x2="n.x - G.node / 2 - 4" :y2="r.y"
                                  stroke="rgba(255,255,255,0.1)" stroke-dasharray="3 5" />
                            <!-- 步驟節點 -->
                            <g :transform="`translate(${n.x}, ${r.y})`" :style="{ '--c': n.cat.color }">
                                <title>{{ n.cat.label }} · {{ n.s.action || n.s.text }}</title>
                                <rect :x="-G.node / 2" :y="-G.node / 2" :width="G.node" :height="G.node"
                                      fill="rgba(4,8,13,0.9)" :stroke="n.cat.color"
                                      :stroke-opacity="j === r.nodes.length - 1 ? 0.9 : 0.4"
                                      :class="{ 'step-now': j === r.nodes.length - 1 && r.running }" />
                                <g class="sicon" :style="{ color: n.cat.color }" transform="translate(-8, -8) scale(0.667)">
                                    <path v-for="(d, k) in ICONS[n.cat.icon]" :key="k" :d="d" />
                                </g>
                                <text x="0" :y="G.node / 2 + 14" text-anchor="middle" class="t-lbl">{{ n.cat.label }}</text>
                                <text x="0" :y="-G.node / 2 - 6" text-anchor="middle" class="t-idx">{{ (r.allLen - r.nodes.length) + j + 1 }}</text>
                            </g>
                        </template>

                        <!-- 推理下一步：游標節點 + 活線 -->
                        <template v-if="r.running">
                            <line :x1="r.nodes.length ? r.nodes[r.nodes.length - 1].x + G.node / 2 + 4 : r.agentR + 4" :y1="r.y"
                                  :x2="r.cursorX - 14" :y2="r.y"
                                  :stroke="C.green" stroke-opacity="0.75" stroke-dasharray="3 5" class="link-live" />
                            <circle r="2.2" :fill="C.green" filter="url(#ops-glow)">
                                <animateMotion dur="0.9s" repeatCount="indefinite"
                                    :path="`M ${r.nodes.length ? r.nodes[r.nodes.length - 1].x + G.node / 2 + 4 : r.agentR + 4} ${r.y} L ${r.cursorX - 14} ${r.y}`" />
                            </circle>
                            <g :transform="`translate(${r.cursorX}, ${r.y})`">
                                <rect x="-13" y="-13" width="26" height="26" fill="none" stroke="#2b3947" stroke-dasharray="4 3" class="cursor-box" />
                                <text x="0" y="4" text-anchor="middle" class="t-cursor blink">_</text>
                            </g>
                        </template>
                    </template>

                    <!-- ═══ watch：掃描活動節點 ═══ -->
                    <template v-else-if="r.a.kind === 'watch'">
                        <line :x1="r.agentR + 4" :y1="r.y" :x2="G.stepX0 - G.node / 2 - 4" :y2="r.y" :stroke="C.cyan" stroke-opacity="0.5" stroke-dasharray="3 5" class="link-live" />
                        <g :transform="`translate(${G.stepX0}, ${r.y})`">
                            <rect :x="-G.node / 2" :y="-G.node / 2" :width="G.node" :height="G.node" fill="rgba(4,8,13,0.9)" :stroke="C.cyan" stroke-opacity="0.7" class="step-now" :style="{ '--c': C.cyan }" />
                            <g class="sicon" :style="{ color: C.cyan }" transform="translate(-8, -8) scale(0.667)">
                                <path v-for="(d, k) in ICONS.eye" :key="k" :d="d" />
                            </g>
                            <text x="0" :y="G.node / 2 + 14" text-anchor="middle" class="t-lbl">視覺判讀</text>
                        </g>
                        <!-- 掃描光束軌道 -->
                        <g :transform="`translate(${G.stepX0 + G.node / 2 + 16}, ${r.y})`">
                            <rect x="0" y="-2" width="200" height="4" fill="rgba(255,255,255,0.05)" />
                            <rect y="-2" width="46" height="4" :fill="C.cyan" opacity="0.8" class="scan-beam" filter="url(#ops-glow)" />
                            <text x="0" y="-10" class="t-lbl" text-anchor="start">每 {{ r.a.interval }}s 截圖 · 已掃 {{ r.a.runs }} 次<tspan v-if="r.a.node"> · {{ r.a.node }}</tspan></text>
                            <text x="0" y="18" class="t-goal" text-anchor="start">{{ trunc(r.a.detail || '等待第一次截圖判讀…', 42) }}</text>
                        </g>
                    </template>

                    <!-- ═══ call：通話活動節點 + 聲紋 ═══ -->
                    <template v-else-if="r.a.kind === 'call'">
                        <line :x1="r.agentR + 4" :y1="r.y" :x2="G.stepX0 - G.node / 2 - 4" :y2="r.y" :stroke="r.color" stroke-opacity="0.5" stroke-dasharray="3 5" class="link-live" />
                        <g :transform="`translate(${G.stepX0}, ${r.y})`">
                            <rect :x="-G.node / 2" :y="-G.node / 2" :width="G.node" :height="G.node" fill="rgba(4,8,13,0.9)" :stroke="r.color" stroke-opacity="0.7" class="step-now" :style="{ '--c': r.color }" />
                            <g class="sicon" :style="{ color: r.color }" transform="translate(-8, -8) scale(0.667)">
                                <path v-for="(d, k) in ICONS.phone" :key="k" :d="d" />
                            </g>
                            <text x="0" :y="G.node / 2 + 14" text-anchor="middle" class="t-lbl">{{ r.a.status === 'pending' ? '撥號中' : '通話中' }}</text>
                        </g>
                        <!-- 聲紋 -->
                        <g :transform="`translate(${G.stepX0 + G.node / 2 + 16}, ${r.y})`">
                            <rect v-for="n in 6" :key="n" :x="(n - 1) * 7" y="-8" width="4" height="16" :fill="r.color" opacity="0.85"
                                  class="eq-bar" :style="{ animationDelay: (n * 0.11) + 's', animationPlayState: r.a.status === 'in_progress' ? 'running' : 'paused' }" />
                            <text x="52" y="-10" class="t-lbl" text-anchor="start">→ {{ r.a.to }} · {{ r.a.turns }} 回合</text>
                            <text x="52" y="18" class="t-goal" text-anchor="start">{{ trunc(r.a.lastLine || '等待接聽…', 40) }}</text>
                        </g>
                    </template>
                </template>
            </svg>
        </div>

        <!-- ░░ 細節卡（每個 agent 的完整資訊） ░░ -->
        <div v-for="a in agents" :key="a.id" class="lane border-t border-(--ops-line)/60 px-5 py-4">
            <!-- 抬頭：身分 + 狀態 + 計時 -->
            <div class="flex flex-wrap items-center gap-2">
                <span class="lane-kind" :class="'lk--' + a.kind">
                    <UiIcon :name="kindMeta[a.kind]?.icon || 'cpu'" :size="13" />
                </span>
                <span class="font-mono text-xs font-semibold text-white">{{ a.name }}</span>
                <span class="font-mono text-[9px] tracking-[0.14em] text-(--ops-ink-faint)">{{ kindMeta[a.kind]?.tag }}</span>
                <span class="st" :class="statusClass(a.status)">
                    <span class="st-dot"></span>{{ statusLabel(a.status) }}
                </span>
                <span v-if="a.domain" class="font-mono text-[10px] text-(--ops-ink-faint)">DOMAIN {{ a.domain }}</span>
                <span v-if="a.node" class="font-mono text-[10px] text-(--ops-cyan)">NODE {{ a.node }}</span>
                <span v-if="a.to" class="font-mono text-[10px] text-(--ops-cyan)">→ {{ a.to }}</span>
                <span class="ml-auto flex items-center gap-3 font-mono text-[10px] tabular-nums text-(--ops-ink-faint)">
                    <span v-if="a.tokens">{{ a.tokens }} TOK</span>
                    <span v-if="a.turns != null">{{ a.turns }} 回合</span>
                    <span v-if="a.runs != null">{{ a.runs }} 次掃描</span>
                    <span v-if="fmtElapsed(a)" class="text-(--ops-green)">T+{{ fmtElapsed(a) }}</span>
                </span>
            </div>

            <!-- 目標 -->
            <p class="mt-1.5 text-sm text-(--ops-ink)">{{ a.title }}</p>

            <!-- coordinator：當前動作細節卡 -->
            <template v-if="a.kind === 'coordinator'">
                <div v-if="currentStep(a)" class="now-card mt-3" :style="{ '--c': catOf(currentStep(a).action).color }">
                    <div class="flex flex-wrap items-center gap-2 font-mono text-xs">
                        <span class="now-badge"><UiIcon :name="catOf(currentStep(a).action).icon" :size="11" /> {{ catOf(currentStep(a).action).label }}</span>
                        <span class="text-(--ops-ink)">{{ currentStep(a).action }}</span>
                        <span v-if="fmtInput(currentStep(a).input)" class="min-w-0 flex-1 truncate text-[10px] text-(--ops-ink-faint)">{{ fmtInput(currentStep(a).input) }}</span>
                    </div>
                    <p v-if="currentStep(a).thought" class="mt-1.5 flex items-start gap-1.5 text-xs text-(--ops-ink-dim)">
                        <UiIcon name="cpu" :size="11" class="mt-0.5 shrink-0" /><span>{{ currentStep(a).thought }}</span>
                    </p>
                    <p v-if="currentStep(a).observation" class="mt-1 flex items-start gap-1.5 text-xs text-(--ops-green)/85">
                        <UiIcon name="eye" :size="11" class="mt-0.5 shrink-0" /><span>{{ currentStep(a).observation }}</span>
                    </p>
                </div>
            </template>

            <!-- chat：即時步驟輸出（終端機式） -->
            <template v-else-if="a.kind === 'chat'">
                <div v-if="a.steps?.length" class="now-card mt-3" style="--c: var(--ops-cyan)">
                    <div class="space-y-1 font-mono text-xs">
                        <p v-for="(s, i) in a.steps.slice(-6)" :key="i" class="flex items-start gap-1.5"
                           :class="i === Math.min(a.steps.length, 6) - 1 ? 'text-(--ops-ink)' : 'text-(--ops-ink-dim)'">
                            <span class="shrink-0 text-(--ops-green)">»</span><span>{{ s.text }}</span>
                        </p>
                        <p class="text-(--ops-green)"><span class="typing-cursor-inline">_</span></p>
                    </div>
                </div>
            </template>

            <!-- watch：最後看到的畫面 -->
            <template v-else-if="a.kind === 'watch'">
                <div class="now-card mt-3" style="--c: var(--ops-cyan)">
                    <div class="flex flex-wrap items-center gap-2 font-mono text-[10px] text-(--ops-ink-faint)">
                        <span class="now-badge"><UiIcon name="eye" :size="11" /> 週期截圖判讀</span>
                        <span>每 {{ a.interval }}s 掃一次</span>
                        <span v-if="a.last_run_at">上次 {{ new Date(a.last_run_at).toLocaleTimeString('en-GB') }}</span>
                        <span v-if="a.expires_at">至 {{ new Date(a.expires_at).toLocaleTimeString('en-GB') }}</span>
                    </div>
                    <p v-if="a.detail" class="mt-2 flex items-start gap-1.5 text-xs text-(--ops-green)/85">
                        <UiIcon name="eye" :size="11" class="mt-0.5 shrink-0" /><span>目前畫面：{{ a.detail }}</span>
                    </p>
                    <p v-else class="mt-2 font-mono text-[10px] text-(--ops-ink-faint)">等待第一次截圖判讀…</p>
                </div>
            </template>

            <!-- call：最後一句逐字稿 -->
            <template v-else-if="a.kind === 'call'">
                <div class="now-card mt-3" style="--c: var(--ops-amber)">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-[10px] tracking-wider text-(--ops-ink-faint)">{{ a.status === 'pending' ? '撥號中 / 等待接聽…' : '通話進行中（回合制對話）' }}</span>
                    </div>
                    <p v-if="a.lastLine" class="mt-2 flex items-start gap-1.5 text-xs text-(--ops-ink-dim)">
                        <UiIcon name="message" :size="11" class="mt-0.5 shrink-0" /><span>{{ a.lastLine }}</span>
                    </p>
                </div>
            </template>
        </div>
    </section>
</template>

<style scoped>
/* ---------- SVG 流程圖 ---------- */
.graph-wrap {
    background:
        radial-gradient(420px circle at 8% 50%, rgba(63, 220, 151, 0.05), transparent 60%),
        rgba(0, 0, 0, 0.25);
}
.graph { display: block; }

.t-core { fill: #fff; font-family: var(--font-mono); font-size: 13px; font-weight: 700; letter-spacing: 0.14em; text-anchor: middle; }
.t-core-sub { fill: #3fdc97; font-family: var(--font-mono); font-size: 7.5px; letter-spacing: 0.3em; text-anchor: middle; }
.t-name { fill: #fff; font-family: var(--font-mono); font-size: 10.5px; font-weight: 600; }
.t-sub { font-family: var(--font-mono); font-size: 8px; letter-spacing: 0.1em; }
.t-goal { fill: #8b98a5; font-size: 10px; }
.t-lbl { fill: #8b98a5; font-family: var(--font-mono); font-size: 8.5px; letter-spacing: 0.06em; }
.t-idx { fill: #56626e; font-family: var(--font-mono); font-size: 7.5px; letter-spacing: 0.1em; }
.t-hidden { fill: #56626e; font-family: var(--font-mono); font-size: 9px; }
.t-cursor { fill: #3fdc97; font-family: var(--font-mono); font-size: 13px; font-weight: 700; }

.sicon path { fill: none; stroke: currentColor; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; }

/* 核心動畫 */
.core-spin { animation: core-rot 9s linear infinite; transform-box: fill-box; transform-origin: center; }
@keyframes core-rot { to { transform: rotate(360deg); } }
.core-pulse { animation: core-pul 3s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
@keyframes core-pul {
    0%, 100% { transform: scale(1); opacity: 0.5; }
    50% { transform: scale(1.12); opacity: 0.12; }
}

/* 邊線流動（虛線位移） */
.edge-flow { stroke-dasharray: 10 130; animation: edge-dash 3.2s linear infinite; }
.edge-flow--fast { animation-duration: 1.6s; }
@keyframes edge-dash { to { stroke-dashoffset: -140; } }

.link-live { animation: link-dash 0.7s linear infinite; }
@keyframes link-dash { to { stroke-dashoffset: -8; } }

/* 節點狀態 */
.agent-box--live { animation: box-glow 2.2s ease-in-out infinite; }
@keyframes box-glow {
    0%, 100% { filter: drop-shadow(0 0 3px color-mix(in srgb, var(--c) 45%, transparent)); }
    50% { filter: drop-shadow(0 0 10px color-mix(in srgb, var(--c) 75%, transparent)); }
}
.step-now { animation: box-glow 1.8s ease-in-out infinite; }
.cursor-box { animation: cursor-rot 4s linear infinite; transform-box: fill-box; transform-origin: center; }
@keyframes cursor-rot { to { transform: rotate(90deg); } }

.blink { animation: blink-a 1.4s step-end infinite; }
@keyframes blink-a { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }

/* 掃描光束 / 聲紋 / 掃描帶 */
.scan-beam { animation: beam-x 2.6s linear infinite; }
@keyframes beam-x { 0% { transform: translateX(0); } 100% { transform: translateX(154px); } }
.eq-bar { transform-box: fill-box; transform-origin: center; animation: eq-y 0.85s ease-in-out infinite; }
@keyframes eq-y { 0%, 100% { transform: scaleY(0.25); } 50% { transform: scaleY(1); } }
.sweep { animation: sweep-x 7s linear infinite; }
@keyframes sweep-x { 0% { transform: translateX(-160px); } 100% { transform: translateX(1300px); } }

/* ---------- 身分方塊 ---------- */
.lane-kind {
    width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--ops-line);
    background: rgba(0, 0, 0, 0.3);
    color: var(--ops-ink-dim);
    flex: 0 0 auto;
}
.lk--coordinator { color: var(--ops-green); border-color: color-mix(in srgb, var(--ops-green) 40%, transparent); }
.lk--chat { color: var(--ops-cyan); border-color: color-mix(in srgb, var(--ops-cyan) 40%, transparent); }
.typing-cursor-inline { animation: blink-a 1s step-end infinite; font-weight: 700; }
.lk--watch { color: var(--ops-cyan); border-color: color-mix(in srgb, var(--ops-cyan) 40%, transparent); }
.lk--call { color: var(--ops-amber); border-color: color-mix(in srgb, var(--ops-amber) 40%, transparent); }

/* ---------- 狀態膠囊 ---------- */
.st {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.08rem 0.45rem;
    border: 1px solid var(--ops-line);
    font-family: var(--font-mono);
    font-size: 0.6rem;
    letter-spacing: 0.1em;
}
.st-dot { width: 5px; height: 5px; background: currentColor; animation: st-pulse 1.6s ease-in-out infinite; }
@keyframes st-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
.st--green { color: var(--ops-green); border-color: color-mix(in srgb, var(--ops-green) 40%, transparent); background: rgba(63, 220, 151, 0.07); }
.st--cyan { color: var(--ops-cyan); border-color: color-mix(in srgb, var(--ops-cyan) 40%, transparent); background: rgba(76, 194, 230, 0.07); }
.st--amber { color: var(--ops-amber); border-color: color-mix(in srgb, var(--ops-amber) 40%, transparent); background: rgba(230, 180, 80, 0.07); }
.st--dim { color: var(--ops-ink-dim); }

/* ---------- 當前動作細節卡 ---------- */
.now-card {
    border: 1px solid color-mix(in srgb, var(--c, var(--ops-green)) 30%, transparent);
    border-left: 2px solid var(--c, var(--ops-green));
    background: rgba(0, 0, 0, 0.28);
    padding: 0.6rem 0.75rem;
}
.now-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.08rem 0.45rem;
    background: color-mix(in srgb, var(--c, var(--ops-green)) 12%, transparent);
    border: 1px solid color-mix(in srgb, var(--c, var(--ops-green)) 40%, transparent);
    color: var(--c, var(--ops-green));
    font-size: 0.62rem;
    letter-spacing: 0.05em;
    white-space: nowrap;
}

/* ---------- 空狀態雷達 ---------- */
.radar-idle {
    position: relative;
    width: 84px;
    height: 84px;
    border: 1px solid var(--ops-line);
    border-radius: 50%;
}
.radar-ring {
    position: absolute;
    border: 1px solid var(--ops-line);
    border-radius: 50%;
}
.radar-ring.r1 { inset: 18px; }
.radar-ring.r2 { inset: 33px; }
.radar-dot {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 4px;
    height: 4px;
    margin: -2px 0 0 -2px;
    background: var(--ops-green);
    border-radius: 50%;
}
.radar-sweep {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: conic-gradient(from 0deg, color-mix(in srgb, var(--ops-green) 25%, transparent), transparent 70deg);
    animation: radar-spin 3.2s linear infinite;
}
@keyframes radar-spin {
    to { transform: rotate(360deg); }
}
</style>
