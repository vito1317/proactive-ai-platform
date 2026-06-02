<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    conversations: { type: Array, default: () => [] },
});

const input = ref('');
const sending = ref(false);
const status = ref('');           // 思考中… / 處理中…
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

const catLabel = (m) => ({ task: '已觸發任務', new_domain: '已新增領域', configure_notify: '已設定通知' }[m?.category]);

function onEnter(e) {
    // 中文/日文輸入法選字組字中（IME composition）→ 不送出
    if (e.isComposing || e.keyCode === 229) return;
    e.preventDefault();
    send();
}

async function send() {
    const msg = input.value.trim();
    if (!msg || sending.value) return;

    lastSent.value = msg;
    input.value = '';
    streamed.value = '';
    errorText.value = '';
    status.value = '送出中…';
    sending.value = true;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let convId = props.conversation.id;

    try {
        const resp = await fetch('/stream/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'text/event-stream' },
            body: JSON.stringify({ conversation_id: convId, message: msg }),
        });
        if (!resp.ok || !resp.body) throw new Error('連線失敗 (' + resp.status + ')');

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
        errorText.value = e.message || 'AI 回覆失敗';
    } finally {
        sending.value = false;
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
        else if (event === 'delta') { status.value = ''; streamed.value += payload.text; }
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
                          class="block truncate rounded-lg px-3 py-2 text-xs"
                          :class="c.id === conversation.id ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5'">{{ c.title }}</Link>
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
                                <span v-if="status && !streamed" class="inline-flex items-center gap-2 text-slate-400">
                                    <span class="inline-flex gap-1"><span class="dot"></span><span class="dot" style="animation-delay:.2s"></span><span class="dot" style="animation-delay:.4s"></span></span>
                                    {{ status }}
                                </span>
                                <span v-else class="whitespace-pre-wrap">{{ streamed }}<span class="cursor">▍</span></span>
                            </template>
                            <template v-else>
                                <div class="whitespace-pre-wrap">{{ m.content }}</div>
                                <div v-if="catLabel(m.meta)" class="mt-1 text-[10px] text-emerald-300/70">⚙ {{ catLabel(m.meta) }}</div>
                            </template>
                        </div>
                    </div>
                    <p v-if="errorText" class="text-center text-xs text-red-400">{{ errorText }}</p>
                </div>

                <!-- 對話框（獨立分離於底部） -->
                <div class="border-t border-white/10 bg-slate-950/60 px-5 py-3">
                    <form class="flex items-end gap-2" @submit.prevent="send">
                        <textarea
                            v-model="input"
                            rows="1"
                            placeholder="跟 AI 說一句話…（Enter 送出，Shift+Enter 換行）"
                            class="inp flex-1 resize-none"
                            @keydown.enter="onEnter"
                        ></textarea>
                        <button type="submit" :disabled="sending || !input.trim()" class="btn-send">
                            {{ sending ? '…' : '送出' }}
                        </button>
                    </form>
                    <p class="mt-1 text-[10px] text-slate-600">即時串流回覆；AI 會自動串接前文並判斷意圖。</p>
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
</style>
