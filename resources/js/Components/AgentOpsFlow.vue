<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import UiIcon from './UiIcon.vue';

/**
 * AGENT OPS · 即時作業流程圖
 * 每個「運行中」的 agent 一條 lane：身分 → 步驟鏈（工具分類 icon）→ 當前動作細節。
 * 資料源：/console/agent-ops（協調者 ReAct 運行、視覺守望、AI 外撥電話），每 3 秒輪詢。
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
        // 附上收到時間，讓 elapsed 在兩次輪詢間可本地推進
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
const CATEGORIES = [
    { re: /(browse|web|url|http|fetch|crawl|surf|page)/i, icon: 'globe', label: '瀏覽網頁', color: 'var(--ops-cyan)' },
    { re: /(gui|tap|click|swipe|screenshot|screen_|type|keyboard|open_app|adb|device|android|mobile|control)/i, icon: 'smartphone', label: '操作裝置', color: 'var(--ops-green)' },
    { re: /(recall|memory|remember)/i, icon: 'database', label: '記憶檢索', color: 'var(--ops-cyan)' },
    { re: /\b(call|dial|twilio|outbound)/i, icon: 'phone', label: '撥打電話', color: 'var(--ops-amber)' },
    { re: /(watch|vision|look|see|observe_screen|image)/i, icon: 'eye', label: '視覺判讀', color: 'var(--ops-cyan)' },
    { re: /(notify|message|mail|send|telegram|line|slack|discord)/i, icon: 'send', label: '發送訊息', color: 'var(--ops-green)' },
    { re: /(log|read_|file|repo)/i, icon: 'file', label: '讀取資料', color: 'var(--ops-ink-dim)' },
    { re: /(query|lookup|match|search|list)/i, icon: 'search', label: '查詢比對', color: 'var(--ops-cyan)' },
    { re: /(propose|record_finding|plan|ticket)/i, icon: 'workflow', label: '規劃提案', color: 'var(--ops-amber)' },
    { re: /(handoff)/i, icon: 'shuffle', label: '移交領域', color: 'var(--ops-amber)' },
    { re: /(finish|reflect|summar)/i, icon: 'flag', label: '收尾反思', color: 'var(--ops-green)' },
    { re: /(get_event_context|context)/i, icon: 'radar', label: '讀取情境', color: 'var(--ops-ink-dim)' },
];
function catOf(action) {
    const hit = CATEGORIES.find(c => c.re.test(action || ''));
    return hit || { icon: 'terminal', label: '工具呼叫', color: 'var(--ops-ink-dim)' };
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
function currentStep(a) {
    return a.steps?.length ? a.steps[a.steps.length - 1] : null;
}
const kindMeta = {
    coordinator: { icon: 'cpu', tag: 'COORDINATOR' },
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

        <!-- Agent lanes -->
        <div v-for="a in agents" :key="a.id" class="lane border-b border-(--ops-line)/60 px-5 py-4 last:border-b-0">
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

            <!-- ═══ coordinator：ReAct 步驟鏈 ═══ -->
            <template v-if="a.kind === 'coordinator'">
                <div v-if="a.steps?.length" class="chain mt-3">
                    <template v-for="(st, i) in a.steps" :key="i">
                        <div class="chain-node" :class="{ 'chain-node--now': i === a.steps.length - 1 && a.status === 'running' }"
                             :style="{ '--c': catOf(st.action).color }"
                             :title="catOf(st.action).label + ' · ' + st.action">
                            <UiIcon :name="catOf(st.action).icon" :size="13" />
                            <span class="chain-lbl">{{ catOf(st.action).label }}</span>
                        </div>
                        <div v-if="i < a.steps.length - 1" class="chain-link" aria-hidden="true"></div>
                    </template>
                    <template v-if="a.status === 'running'">
                        <div class="chain-link chain-link--live" aria-hidden="true"></div>
                        <div class="chain-node chain-node--next" title="推理下一步中">
                            <span class="typing-cursor font-mono text-xs font-bold">_</span>
                        </div>
                    </template>
                </div>

                <!-- 當前動作細節卡 -->
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

            <!-- ═══ watch：視覺守望（掃描動畫 + 最後看到的畫面） ═══ -->
            <template v-else-if="a.kind === 'watch'">
                <div class="now-card mt-3" style="--c: var(--ops-cyan)">
                    <div class="flex flex-wrap items-center gap-2 font-mono text-[10px] text-(--ops-ink-faint)">
                        <span class="now-badge"><UiIcon name="eye" :size="11" /> 週期截圖判讀</span>
                        <span>每 {{ a.interval }}s 掃一次</span>
                        <span v-if="a.last_run_at">上次 {{ new Date(a.last_run_at).toLocaleTimeString('en-GB') }}</span>
                        <span v-if="a.expires_at">至 {{ new Date(a.expires_at).toLocaleTimeString('en-GB') }}</span>
                    </div>
                    <div class="scanline-track mt-2" aria-hidden="true"><div class="scanline-beam" :style="{ animationDuration: Math.max(2, a.interval) + 's' }"></div></div>
                    <p v-if="a.detail" class="mt-2 flex items-start gap-1.5 text-xs text-(--ops-green)/85">
                        <UiIcon name="eye" :size="11" class="mt-0.5 shrink-0" /><span>目前畫面：{{ a.detail }}</span>
                    </p>
                    <p v-else class="mt-2 font-mono text-[10px] text-(--ops-ink-faint)">等待第一次截圖判讀…</p>
                </div>
            </template>

            <!-- ═══ call：AI 外撥電話（聲紋動畫 + 最後一句） ═══ -->
            <template v-else-if="a.kind === 'call'">
                <div class="now-card mt-3" style="--c: var(--ops-amber)">
                    <div class="flex items-center gap-3">
                        <div class="eq" :class="{ 'eq--live': a.status === 'in_progress' }" aria-hidden="true">
                            <span v-for="n in 5" :key="n" :style="{ animationDelay: (n * 0.12) + 's' }"></span>
                        </div>
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

/* ---------- 步驟鏈 ---------- */
.chain {
    display: flex;
    align-items: center;
    overflow-x: auto;
    padding: 2px 0 6px;
    scrollbar-width: thin;
}
.chain-node {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    min-width: 52px;
    padding: 7px 6px 5px;
    border: 1px solid color-mix(in srgb, var(--c, var(--ops-ink-dim)) 35%, transparent);
    background: rgba(0, 0, 0, 0.3);
    color: var(--c, var(--ops-ink-dim));
}
.chain-lbl {
    font-size: 0.56rem;
    letter-spacing: 0.05em;
    color: var(--ops-ink-dim);
    white-space: nowrap;
}
.chain-node--now {
    border-color: var(--c);
    box-shadow: 0 0 14px -4px var(--c), inset 0 0 10px color-mix(in srgb, var(--c) 10%, transparent);
    animation: now-glow 2s ease-in-out infinite;
}
@keyframes now-glow {
    0%, 100% { box-shadow: 0 0 14px -4px var(--c), inset 0 0 10px color-mix(in srgb, var(--c) 10%, transparent); }
    50% { box-shadow: 0 0 20px -2px var(--c), inset 0 0 14px color-mix(in srgb, var(--c) 16%, transparent); }
}
.chain-node--next {
    min-width: 34px;
    padding: 7px 4px;
    border-style: dashed;
    border-color: var(--ops-line-strong);
    color: var(--ops-green);
}
.chain-link {
    flex: 0 0 auto;
    width: 26px;
    height: 1px;
    margin: 0 2px 14px;
    background: repeating-linear-gradient(90deg, var(--ops-line-strong) 0 3px, transparent 3px 6px);
}
.chain-link--live {
    background: repeating-linear-gradient(90deg, var(--ops-green) 0 3px, transparent 3px 6px);
    animation: link-flow 0.6s linear infinite;
}
@keyframes link-flow {
    0% { background-position: 0 0; }
    100% { background-position: 6px 0; }
}

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

/* ---------- 視覺守望掃描條 ---------- */
.scanline-track {
    position: relative;
    height: 4px;
    background: rgba(255, 255, 255, 0.04);
    overflow: hidden;
}
.scanline-beam {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 22%;
    background: linear-gradient(90deg, transparent, var(--ops-cyan), transparent);
    animation: scan-x linear infinite;
}
@keyframes scan-x {
    0% { left: -22%; }
    100% { left: 100%; }
}

/* ---------- 通話聲紋 ---------- */
.eq {
    display: inline-flex;
    align-items: flex-end;
    gap: 2px;
    height: 16px;
}
.eq span {
    width: 3px;
    height: 4px;
    background: var(--ops-amber);
    opacity: 0.5;
}
.eq--live span {
    opacity: 1;
    animation: eq-bounce 0.9s ease-in-out infinite;
}
@keyframes eq-bounce {
    0%, 100% { height: 4px; }
    50% { height: 15px; }
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

.typing-cursor {
    animation: blink 1s step-end infinite;
    color: var(--ops-green);
}
@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}
</style>
