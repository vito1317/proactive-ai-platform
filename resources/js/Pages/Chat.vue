<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions({ gfm: true, breaks: true });
// 把 AI 回覆的 Markdown 轉成安全的 HTML（清消 XSS）
function renderMd(text) {
    if (!text) return '';
    try { return DOMPurify.sanitize(marked.parse(String(text))); } catch { return String(text); }
}

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    conversations: { type: Array, default: () => [] },
});

const input = ref('');
const sending = ref(false);
const status = ref('');           // 思考中… / 處理中…
const steps = ref([]);            // AI 活動軌跡（每步在幹嘛）
const streamed = ref('');         // 正在串流的 AI 回覆
const lastSent = ref('');
const errorText = ref('');
const scroller = ref(null);

/* ---------- 真實進度追蹤 ---------- */
const eventStatuses = ref({}); // { event_id: { status, runs, percent } }
async function trackEvent(id) {
    if (!id || eventStatuses.value[id]?.status === 'completed') return;
    try {
        const resp = await fetch(`/chat/events/${id}`);
        const data = await resp.json();
        
        // 計算進度百分比 (基於運行狀態與步數)
        let percent = 0;
        if (data.status === 'completed') percent = 100;
        else if (data.status === 'processing') {
            const run = data.runs?.[0];
            if (run) {
                const stepCount = run.steps?.length || 0;
                percent = Math.min(95, 20 + (stepCount * 15)); // 起跳 20%，每步 +15%，封頂 95%
            } else percent = 10;
        } else if (data.status === 'failed') percent = 100;

        eventStatuses.value[id] = { ...data, percent };

        // 如果還沒結束，3 秒後再查一次
        if (data.status !== 'completed' && data.status !== 'failed' && data.status !== 'rejected') {
            setTimeout(() => trackEvent(id), 3000);
        }
    } catch { /* ignore */ }
}

const view = computed(() => {
    const list = [...props.messages];
    if (sending.value) {
        list.push({ id: 'pending-u', role: 'user', content: lastSent.value });
        list.push({ id: 'pending-a', role: 'assistant', content: streamed.value, streaming: true });
    }
    return list;
});

function scrollDown() {
    nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; });
}
onMounted(scrollDown);
watch([streamed, status, () => props.messages.length], scrollDown);

// 若最後一則是使用者訊息且尚無 AI 回覆 → 代表回覆仍在背景生成（例如剛重新整理、
// 或關掉串流連線後）。輪詢直到回覆出現，避免「重整後 AI像沒回應」。
const awaitingReply = computed(() => {
    const m = props.messages;
    return !sending.value && m.length > 0 && m[m.length - 1].role === 'user';
});
let pollTimer = null;
let pollCount = 0;
function syncAwaiting() {
    if (awaitingReply.value && !pollTimer) {
        pollCount = 0;
        // 如果有背景事件，開始追蹤真實進度
        if (props.conversation.active_event_id) {
            trackEvent(props.conversation.active_event_id);
        }
        pollTimer = setInterval(() => {
            if (++pollCount > 90) { clearInterval(pollTimer); pollTimer = null; return; } // ~5 分鐘上限
            router.reload({ only: ['messages', 'conversation'], preserveScroll: true, preserveState: true });
        }, 3500);
    } else if (!awaitingReply.value && pollTimer) {
        clearInterval(pollTimer); pollTimer = null;
    }
}
onMounted(syncAwaiting);
watch(() => props.conversation.active_event_id, (id) => { if (id) trackEvent(id); });
watch(awaitingReply, syncAwaiting);
watch(() => props.messages, (msgs) => {
    msgs.forEach(m => {
        if (m.meta?.event_id) trackEvent(m.meta.event_id);
    });
}, { immediate: true });
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer); });

const catLabel = (m) => ({ task: '已觸發任務', new_domain: '已新增領域', configure_notify: '已設定通知' }[m?.category]);

function onEnter(e) {
    // 中文/日文輸入法選字組字中（IME composition）→ 不送出
    if (e.isComposing || e.keyCode === 229) return;
    e.preventDefault();
    send();
}

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
let controller = null;

/* ---------- 歷程動畫對照表 ---------- */
const stepIcons = {
    '感知': '📡', '分析': '🧠', '載入': '📚', '讀取': '📚', '修改': '🛠️', 
    '執行': '⚡', '驗證': '🛡️', '通知': '🔔', '連線': '🔗', '喚醒': '🦾',
    '思考': '💡', '搜尋': '🔍', '儲存': '💾'
};
const getStepIcon = (txt) => Object.entries(stepIcons).find(([k]) => txt.includes(k))?.[1] || '🔹';
const getStepColor = (txt) => {
    if (txt.includes('載入') || txt.includes('讀取')) return '#fbbf24'; // amber
    if (txt.includes('修改') || txt.includes('執行')) return '#f472b6'; // pink
    if (txt.includes('驗證')) return '#10b981'; // emerald
    if (txt.includes('喚醒') || txt.includes('分析')) return '#a855f7'; // purple
    return '#38bdf8'; // sky
};

// 通知後端設定中止旗標（背景生成迴圈會即時停下並存檔）
async function requestStop() {
    try {
        await fetch('/chat/stop', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ conversation_id: props.conversation.id }),
        });
    } catch { /* ignore */ }
}

// 終止回覆（串流中）：通知後端 + 中斷前端串流
async function stopReply() {
    if (!sending.value) return;
    status.value = '已終止';
    await requestStop();
    controller?.abort();
}

// 終止背景生成（重整後的「生成中」狀態，已無串流連線可中斷）
async function stopAwaiting() {
    status.value = '終止中…';
    await requestStop();
}

async function send() {
    const msg = input.value.trim();
    if (!msg) return;

    // 插話：生成中又送新訊息 → 先終止目前回覆，再送新的
    if (sending.value) {
        await stopReply();
        await new Promise((r) => setTimeout(r, 250)); // 等舊串流收尾
    }

    lastSent.value = msg;
    input.value = '';
    streamed.value = '';
    steps.value = [];
    errorText.value = '';
    status.value = '送出中…';
    sending.value = true;
    controller = new AbortController();

    let convId = props.conversation.id;

    try {
        const resp = await fetch('/stream/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), Accept: 'text/event-stream' },
            body: JSON.stringify({ conversation_id: convId, message: msg }),
            signal: controller.signal,
        });
        if (!resp.ok || !resp.body) throw new Error('連線失敗 (' + resp.status + ')');
        status.value = '連線中…';

        const reader = resp.body.getReader();
        const dec = new TextDecoder();
        let buf = '';
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buf += dec.decode(value, { stream: true });
            let i;
            while ((i = buf.indexOf('\n\n')) >= 0) {
                handleEvent(buf.slice(0, i));
                buf = buf.slice(i + 2);
            }
        }
    } catch (e) {
        if (e.name !== 'AbortError') errorText.value = e.message || 'AI 回覆失敗';
    } finally {
        sending.value = false;
        controller = null;
        // 同步伺服器端已持久化的訊息（含標題/側欄）
        router.reload({ only: ['messages', 'conversations', 'conversation'], preserveScroll: true, preserveState: true });
    }

    function handleEvent(block) {
        let event = 'message';
        let data = '';
        for (const line of block.split('\n')) {
            if (line.startsWith('event:')) event = line.slice(6).trim();
            else if (line.startsWith('data:')) data += line.slice(5).trim();
        }
        if (!data) return;
        let payload = {};
        try { payload = JSON.parse(data); } catch { return; }

        if (event === 'status') { if (!streamed.value) status.value = payload.text; }
        else if (event === 'step') { steps.value.push(payload.text); status.value = ''; }
        else if (event === 'delta') { status.value = ''; streamed.value += payload.text; }
        else if (event === 'stopped') { status.value = '已終止'; }
        else if (event === 'done') { convId = payload.conversation_id ?? convId; }
        else if (event === 'error') { errorText.value = payload.text; }
    }
}

function newChat() { router.post('/chat/new'); }
</script>

<template>
    <Head title="指揮 AI · 對話" />

    <div class="chat-root">
        <div class="bg-glow"></div>
        <div class="relative z-10 flex h-screen">
            <!-- 會話側欄 -->
            <aside class="hidden w-60 shrink-0 flex-col border-r border-white/10 bg-slate-950/60 p-3 md:flex">
                <Link href="/" class="mb-3 text-xs text-slate-400 hover:text-white">← 回中控台</Link>
                <button class="mb-3 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500" @click="newChat">＋ 新對話</button>
                <div class="flex-1 space-y-1 overflow-y-auto">
                    <Link v-for="c in conversations" :key="c.id" :href="`/chat?c=${c.id}`"
                          class="flex items-center gap-1.5 truncate rounded-lg px-3 py-2 text-xs"
                          :class="c.id === conversation.id ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5'">
                        <span v-if="c.channel === 'tg'" class="shrink-0 rounded bg-sky-500/20 px-1 text-[9px] text-sky-300">TG</span>
                        <span v-else-if="c.channel === 'line'" class="shrink-0 rounded bg-emerald-500/20 px-1 text-[9px] text-emerald-300">LINE</span>
                        <span class="truncate">{{ c.title }}</span>
                    </Link>
                </div>
            </aside>

            <!-- 對話主區 -->
            <main class="flex min-w-0 flex-1 flex-col">
                <header class="flex items-center gap-2 border-b border-white/10 px-5 py-3">
                    <span class="ooda-dot"></span>
                    <h1 class="font-semibold text-white">指揮 AI</h1>
                    <span class="text-xs text-slate-500">· 即時串流、自動記住上下文與判斷意圖</span>
                </header>

                <div ref="scroller" class="flex-1 space-y-4 overflow-y-auto px-5 py-6">
                    <div v-if="!view.length" class="mx-auto max-w-md pt-10 text-center text-sm text-slate-500">
                        你好 👋 我是 PAI 指揮 AI。用白話跟我說，我會自動判斷要「執行任務、新增領域、還是設定通知」。
                    </div>

                    <TransitionGroup name="msg">
                        <div v-for="m in view" :key="m.id" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[80%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed"
                                :class="m.role === 'user' ? 'bg-indigo-600 text-white shadow-[0_4px_12px_rgba(79,70,229,0.3)]' : 'border border-white/10 bg-white/5 text-slate-200'">
                                <!-- 串流中的 AI 泡泡 -->
                                <template v-if="m.streaming">
                                    <!-- AI 執行歷程動畫圖 (Visual Trace) -->
                                    <div v-if="steps.length || (!streamed && status)" class="trace-container">
                                        <div v-for="(s, i) in (steps.length ? steps : [status])" :key="i" class="trace-item">
                                            <div v-if="i < (steps.length ? steps.length : 1) - 1" 
                                                 class="trace-line trace-line--active" 
                                                 :style="{ '--from-color': getStepColor(s), '--to-color': steps[i+1] ? getStepColor(steps[i+1]) : 'transparent' }">
                                            </div>
                                            <div class="trace-node trace-node--active" 
                                                 :class="{ 'trace-node--pulsing': !streamed && i === (steps.length ? steps.length : 1) - 1 }"
                                                 :style="{ '--accent': getStepColor(s) }">
                                                {{ getStepIcon(s) }}
                                            </div>
                                            <div class="trace-label trace-label--active">
                                                {{ s }}<span v-if="i === (steps.length ? steps.length : 1) - 1 && !streamed" class="typing-cursor-chat">_</span>
                                            </div>
                                        </div>
                                    </div>
                                    <span v-if="streamed" class="md" v-html="renderMd(streamed)"></span><span v-if="streamed" class="cursor">▍</span>
                                </template>
                                <template v-else>
                                    <!-- 歷史訊息的歷程圖 (如果有 trace) -->
                                    <div v-if="m.meta?.trace?.length" class="trace-container opacity-60 hover:opacity-100 transition-opacity">
                                        <div v-for="(s, i) in m.meta.trace" :key="i" class="trace-item">
                                            <div v-if="i < m.meta.trace.length - 1" class="trace-line" :style="{ '--from-color': getStepColor(s), '--to-color': getStepColor(m.meta.trace[i+1]) }"></div>
                                            <div class="trace-node" :style="{ '--accent': getStepColor(s) }">{{ getStepIcon(s) }}</div>
                                            <div class="trace-label">{{ s }}</div>
                                        </div>
                                        <div class="mb-2 border-b border-white/5"></div>
                                    </div>
                                    <div v-if="m.role === 'user'" class="whitespace-pre-wrap">{{ m.content }}</div>
                                    <div v-else class="md" v-html="renderMd(m.content)"></div>
                                    
                                    <!-- 操作進度與動畫 (歷史項目) -->
                                    <div v-if="catLabel(m.meta)" class="mt-3 space-y-1.5 border-t border-white/10 pt-2">
                                        <div class="flex items-center justify-between text-[10px] font-bold tracking-tighter uppercase">
                                            <span class="text-emerald-400 flex items-center gap-1">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_#10b981]" :class="{ 'animate-pulse': (eventStatuses[m.meta.event_id]?.percent || 100) < 100 }"></span>
                                                {{ catLabel(m.meta) }}
                                            </span>
                                            <span :class="(eventStatuses[m.meta.event_id]?.percent || 100) < 100 ? 'text-sky-400' : 'text-slate-500'">
                                                {{ eventStatuses[m.meta.event_id]?.percent || 100 }}% {{ (eventStatuses[m.meta.event_id]?.percent || 100) < 100 ? 'PROCESSING' : 'SUCCESS' }}
                                            </span>
                                        </div>
                                        <div class="h-1 w-full overflow-hidden rounded-full bg-white/5">
                                            <div class="h-full bg-gradient-to-r from-emerald-600/50 to-emerald-400 shadow-[0_0_12px_rgba(52,211,153,0.3)] transition-all duration-1000"
                                                 :style="{ width: `${eventStatuses[m.meta.event_id]?.percent || 100}%` }"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </TransitionGroup>
                    
                    <!-- 重整後仍在背景生成的回覆：顯示等待中 -->
                    <div v-if="awaitingReply" class="flex justify-start">
                        <div class="max-w-[85%] mini-terminal rounded-2xl border border-indigo-500/30 bg-slate-900/60 p-4 shadow-[0_0_20px_rgba(79,70,229,0.1)]">
                            <div class="flex items-start gap-4">
                                <div class="relative h-14 w-14 shrink-0">
                                    <div class="absolute inset-0 rounded-full border-2 border-dashed border-indigo-500/40 animate-[spin_10s_linear_infinite]"></div>
                                    <div class="absolute inset-2 rounded-full border border-sky-400/30 animate-pulse"></div>
                                    <div class="absolute inset-0 rounded-full bg-[conic-gradient(from_0deg,transparent_0deg,rgba(56,189,248,0.2)_360deg)] animate-[spin_3s_linear_infinite] opacity-60"></div>
                                    <div class="absolute inset-0 flex items-center justify-center text-2xl animate-[bounce_2s_ease-in-out_infinite]">🧠</div>
                                </div>
                                <div class="flex-1 space-y-2 font-mono text-xs">
                                    <div class="flex items-center justify-between text-sky-300">
                                        <span class="font-bold tracking-widest text-[10px] uppercase">NEURAL_SYNC // 意圖擷取中</span>
                                        <span class="flex gap-1.5"><span class="dot-sky"></span><span class="dot-sky" style="animation-delay:.2s"></span><span class="dot-sky" style="animation-delay:.4s"></span></span>
                                    </div>
                                    <div class="grid grid-cols-1 gap-1 text-[10px] text-slate-400">
                                        <template v-if="eventStatuses[conversation.active_event_id]?.runs?.[0]?.steps?.length">
                                            <div v-for="(s, idx) in eventStatuses[conversation.active_event_id].runs[0].steps" :key="idx" class="flex items-center gap-2">
                                                <span class="text-emerald-500 opacity-70">>></span> {{ s }} [OK]
                                            </div>
                                            <div class="flex items-center gap-2"><span class="text-sky-400 animate-pulse">>></span> 正在產出最終結果...<span class="typing-cursor-chat">_</span></div>
                                        </template>
                                        <template v-else>
                                            <div class="flex items-center gap-2"><span class="text-emerald-500 opacity-70">>></span> 掃描通訊協定... [OK]</div>
                                            <div class="flex items-center gap-2"><span class="text-emerald-500 opacity-70">>></span> 載入會話上下文... [OK]</div>
                                            <div class="flex items-center gap-2"><span class="text-sky-400 animate-pulse">>></span> 正在啟動認知引擎...<span class="typing-cursor-chat">_</span></div>
                                        </template>
                                    </div>
                                    <div class="mt-2 space-y-1.5 border-t border-white/5 pt-2">
                                        <div class="h-1 w-full overflow-hidden rounded-full bg-white/5">
                                            <div class="h-full bg-indigo-500 shadow-[0_0_8px_#6366f1] transition-all duration-1000"
                                                 :style="{ width: `${eventStatuses[conversation.active_event_id]?.percent || 15}%` }"></div>
                                        </div>
                                        <div class="flex items-center justify-between text-[9px] text-slate-500 italic">
                                            <span>{{ status === '終止中…' ? '終止中…' : '進度：' + (eventStatuses[conversation.active_event_id]?.percent || 15) + '%' }}</span>
                                            <button type="button" class="shrink-0 rounded-md border border-red-400/40 bg-red-500/10 px-2 py-0.5 text-[11px] text-red-300 hover:bg-red-500/20" @click="stopAwaiting">■ 終止</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-if="errorText" class="text-center text-xs text-red-400">{{ errorText }}</p>
                </div>

                <div v-if="conversation.channel" class="border-t border-white/10 bg-slate-950/60 px-5 py-3 text-center text-xs text-slate-500">
                    {{ conversation.channel === 'tg' ? '✈️ Telegram' : '💬 LINE' }} 會話 — bot 自動回覆中，此處為唯讀檢視
                </div>
                <div v-else class="border-t border-white/10 bg-slate-950/60 px-5 py-3">
                    <div v-if="sending" class="mb-2 flex items-center justify-between gap-2 text-xs text-indigo-300">
                        <span class="inline-flex items-center gap-2">
                            <span class="dot"></span>
                            {{ status || (streamed ? '生成回覆中…' : (steps.length ? steps[steps.length - 1] : '處理中…')) }}
                        </span>
                        <button type="button" class="rounded-md border border-red-400/40 bg-red-500/10 px-2 py-0.5 text-red-300 hover:bg-red-500/20" @click="stopReply">■ 終止</button>
                    </div>
                    <form class="flex items-end gap-2" @submit.prevent="send">
                        <textarea v-model="input" rows="1" :placeholder="sending ? '生成中也可直接打字插話…' : '跟 AI 說一句話…'" class="inp flex-1 resize-none" @keydown.enter="onEnter"></textarea>
                        <button v-if="sending" type="button" class="btn-send !bg-red-600 hover:!bg-red-500" @click="stopReply">■</button>
                        <button type="submit" :disabled="!input.trim()" class="btn-send">{{ sending ? '插話' : '送出' }}</button>
                    </form>
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped>
.chat-root { position: relative; height: 100vh; background: #020617; color: #e2e8f0; overflow: hidden; }
.bg-glow { position: absolute; inset: 0; pointer-events: none;
    background: radial-gradient(700px circle at 20% 0%, rgba(99,102,241,0.14), transparent 45%), radial-gradient(700px circle at 90% 100%, rgba(34,211,238,0.1), transparent 45%); }
.inp { border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.12); background: rgba(2,6,23,0.6); color: #e2e8f0; padding: 0.6rem 0.8rem; font-size: 0.875rem; max-height: 160px; }
.inp:focus { outline: none; border-color: #6366f1; }
.btn-send { border-radius: 0.75rem; background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff; padding: 0.6rem 1.1rem; font-size: 0.85rem; font-weight: 600; }
.btn-send:disabled { opacity: 0.5; }
.ooda-dot { width: 8px; height: 8px; border-radius: 9999px; background: #34d399; box-shadow: 0 0 8px #34d399; }
.dot { width: 6px; height: 6px; border-radius: 9999px; background: #94a3b8; display: inline-block; animation: blink 1s infinite; }
.dot-sky { width: 4px; height: 4px; border-radius: 9999px; background: #38bdf8; display: inline-block; animation: blink 1s infinite; }
.cursor { animation: blink 1s steps(1) infinite; color: #818cf8; }
@keyframes blink { 0%,100% { opacity: .2 } 50% { opacity: 1 } }
.md { font-size: 0.875rem; line-height: 1.6; word-break: break-word; }
.md :first-child { margin-top: 0; }
.md :last-child { margin-bottom: 0; }
.md p { margin: 0.4rem 0; }
.md h1, .md h2, .md h3 { font-weight: 700; margin: 0.7rem 0 0.4rem; line-height: 1.3; }
.md h1 { font-size: 1.15rem; } .md h2 { font-size: 1.05rem; } .md h3 { font-size: 0.95rem; }
.md ul, .md ol { margin: 0.4rem 0; padding-left: 1.3rem; }
.md ul { list-style: disc; } .md ol { list-style: decimal; }
.md li { margin: 0.15rem 0; }
.md a { color: #818cf8; text-decoration: underline; }
.md code { background: rgba(255,255,255,0.1); padding: 0.1rem 0.35rem; border-radius: 0.3rem; font-size: 0.82em; }
.md pre { background: rgba(2,6,23,0.7); border: 1px solid rgba(255,255,255,0.1); border-radius: 0.6rem; padding: 0.7rem 0.9rem; margin: 0.5rem 0; overflow-x: auto; }
.md pre code { background: none; padding: 0; font-size: 0.8rem; line-height: 1.5; }
.md blockquote { border-left: 3px solid rgba(129,140,248,0.5); padding-left: 0.7rem; margin: 0.5rem 0; color: #cbd5e1; }
.md table { border-collapse: collapse; margin: 0.5rem 0; font-size: 0.82rem; }
.md th, .md td { border: 1px solid rgba(255,255,255,0.15); padding: 0.3rem 0.55rem; }
.md hr { border: 0; border-top: 1px solid rgba(255,255,255,0.12); margin: 0.7rem 0; }
.md strong { font-weight: 700; color: #f1f5f9; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
@keyframes spin-reverse { 0% { transform: rotate(0deg); } 100% { transform: rotate(-360deg); } }
@keyframes terminal-scan { 0% { background-position: 0% -100%; } 100% { background-position: 0% 200%; } }
.mini-terminal { position: relative; overflow: hidden; background-image: linear-gradient(0deg, transparent 0%, rgba(56,189,248,0.1) 50%, transparent 100%); background-size: 100% 200%; animation: terminal-scan 3s linear infinite; box-shadow: inset 0 0 10px rgba(0,0,0,0.5); }
.typing-cursor-chat { animation: blink 1s step-end infinite; font-weight: bold; color: #38bdf8; }
.trace-container { display: flex; flex-direction: column; gap: 0; margin-bottom: 0.75rem; padding-left: 0.5rem; }
.trace-item { display: flex; align-items: flex-start; gap: 0.75rem; position: relative; }
.trace-line { position: absolute; left: 9px; top: 20px; bottom: -4px; width: 2px; background: linear-gradient(to bottom, var(--from-color), var(--to-color, transparent)); opacity: 0.3; z-index: 1; }
.trace-line--active { opacity: 0.8; box-shadow: 0 0 8px var(--from-color); }
.trace-node { position: relative; z-index: 10; width: 20px; height: 20px; border-radius: 50%; background: #1e293b; border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; font-size: 10px; transition: all 0.3s ease; }
.trace-node--active { border-color: var(--accent); box-shadow: 0 0 12px var(--accent), inset 0 0 4px var(--accent); transform: scale(1.1); }
.trace-label { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 11px; color: #94a3b8; padding-top: 2px; transition: color 0.3s; }
.trace-label--active { color: #f1f5f9; text-shadow: 0 0 8px rgba(255,255,255,0.3); }
@keyframes pulse-ring { 0% { transform: scale(0.8); opacity: 0.5; } 100% { transform: scale(1.5); opacity: 0; } }
.trace-node--pulsing::before { content: ''; position: absolute; inset: -4px; border-radius: 50%; border: 1px solid var(--accent); animation: pulse-ring 1.5s cubic-bezier(0.24, 0, 0.38, 1) infinite; }
</style>
