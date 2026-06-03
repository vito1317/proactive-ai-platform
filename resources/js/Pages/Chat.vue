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
// 或關掉串流連線後）。輪詢直到回覆出現，避免「重整後 AI 像沒回應」。
const awaitingReply = computed(() => {
    const m = props.messages;
    return !sending.value && m.length > 0 && m[m.length - 1].role === 'user';
});
let pollTimer = null;
let pollCount = 0;
function syncAwaiting() {
    if (awaitingReply.value && !pollTimer) {
        pollCount = 0;
        pollTimer = setInterval(() => {
            if (++pollCount > 90) { clearInterval(pollTimer); pollTimer = null; return; } // ~5 分鐘上限
            router.reload({ only: ['messages', 'conversation'], preserveScroll: true, preserveState: true });
        }, 3500);
    } else if (!awaitingReply.value && pollTimer) {
        clearInterval(pollTimer); pollTimer = null;
    }
}
onMounted(syncAwaiting);
watch(awaitingReply, syncAwaiting);
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

// 終止回覆：通知後端停止生成 + 中斷前端串流
async function stopReply() {
    if (!sending.value) return;
    status.value = '已終止';
    try {
        await fetch('/chat/stop', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ conversation_id: props.conversation.id }),
        });
    } catch { /* ignore */ }
    controller?.abort();
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

                    <div v-for="m in view" :key="m.id" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[80%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed"
                             :class="m.role === 'user' ? 'bg-indigo-600 text-white' : 'border border-white/10 bg-white/5 text-slate-200'">
                            <!-- 串流中的 AI 泡泡 -->
                            <template v-if="m.streaming">
                                <!-- AI 活動軌跡：每一步在幹嘛 -->
                                <div v-if="steps.length" class="mb-2 space-y-1 border-l-2 border-indigo-400/40 pl-2.5">
                                    <div v-for="(s, i) in steps" :key="i"
                                         class="flex items-center gap-1.5 text-xs"
                                         :class="i === steps.length - 1 && !streamed ? 'text-indigo-300' : 'text-slate-500'">
                                        <span v-if="i === steps.length - 1 && !streamed" class="dot"></span>
                                        <span v-else class="text-emerald-400/70">✓</span>
                                        {{ s }}
                                    </div>
                                </div>
                                <span v-if="status && !streamed && !steps.length" class="inline-flex items-center gap-2 text-slate-400">
                                    <span class="inline-flex gap-1"><span class="dot"></span><span class="dot" style="animation-delay:.2s"></span><span class="dot" style="animation-delay:.4s"></span></span>
                                    {{ status }}
                                </span>
                                <span v-if="streamed" class="md" v-html="renderMd(streamed)"></span><span v-if="streamed" class="cursor">▍</span>
                            </template>
                            <template v-else>
                                <!-- 使用者訊息純文字；AI 回覆渲染 Markdown -->
                                <div v-if="m.role === 'user'" class="whitespace-pre-wrap">{{ m.content }}</div>
                                <div v-else class="md" v-html="renderMd(m.content)"></div>
                                <div v-if="catLabel(m.meta)" class="mt-1 text-[10px] text-emerald-300/70">⚙ {{ catLabel(m.meta) }}</div>
                            </template>
                        </div>
                    </div>
                    <!-- 重整後仍在背景生成的回覆：顯示等待中（完成會自動出現） -->
                    <div v-if="awaitingReply" class="flex justify-start">
                        <div class="max-w-[80%] rounded-2xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-slate-400">
                            <span class="inline-flex items-center gap-2">
                                <span class="inline-flex gap-1"><span class="dot"></span><span class="dot" style="animation-delay:.2s"></span><span class="dot" style="animation-delay:.4s"></span></span>
                                AI 回覆生成中…（可離開，完成後會自動出現）
                            </span>
                        </div>
                    </div>
                    <p v-if="errorText" class="text-center text-xs text-red-400">{{ errorText }}</p>
                </div>

                <!-- 對話框（獨立分離於底部）；TG/LINE session 為唯讀檢視 -->
                <div v-if="conversation.channel" class="border-t border-white/10 bg-slate-950/60 px-5 py-3 text-center text-xs text-slate-500">
                    {{ conversation.channel === 'tg' ? '✈️ Telegram' : '💬 LINE' }} 會話 — bot 自動回覆中，此處為唯讀檢視
                </div>
                <div v-else class="border-t border-white/10 bg-slate-950/60 px-5 py-3">
                    <!-- 生成中的進度條（不再卡在「送出中」）-->
                    <div v-if="sending" class="mb-2 flex items-center justify-between gap-2 text-xs text-indigo-300">
                        <span class="inline-flex items-center gap-2">
                            <span class="dot"></span>
                            {{ status || (streamed ? '生成回覆中…' : (steps.length ? steps[steps.length - 1] : '處理中…')) }}
                        </span>
                        <button type="button" class="rounded-md border border-red-400/40 bg-red-500/10 px-2 py-0.5 text-red-300 hover:bg-red-500/20" @click="stopReply">■ 終止</button>
                    </div>
                    <form class="flex items-end gap-2" @submit.prevent="send">
                        <textarea
                            v-model="input"
                            rows="1"
                            :placeholder="sending ? '生成中也可直接打字插話（會打斷目前回覆）…' : '跟 AI 說一句話…（Enter 送出，Shift+Enter 換行）'"
                            class="inp flex-1 resize-none"
                            @keydown.enter="onEnter"
                        ></textarea>
                        <button v-if="sending" type="button" class="btn-send !bg-red-600 hover:!bg-red-500" @click="stopReply">■</button>
                        <button type="submit" :disabled="!input.trim()" class="btn-send">{{ sending ? '插話' : '送出' }}</button>
                    </form>
                    <p class="mt-1 text-[10px] text-slate-600">即時串流；可隨時按「終止」停止，或直接打字「插話」打斷目前回覆。</p>
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
.cursor { animation: blink 1s steps(1) infinite; color: #818cf8; }
@keyframes blink { 0%,100% { opacity: .2 } 50% { opacity: 1 } }

/* Markdown 渲染（對話框）：補回 Tailwind preflight 移除的清單/標題等樣式 */
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
</style>
