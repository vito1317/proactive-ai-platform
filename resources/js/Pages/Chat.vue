<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    conversations: { type: Array, default: () => [] },
});

const form = useForm({ conversation_id: props.conversation.id, message: '' });
const lastSent = ref('');
const scroller = ref(null);

const view = computed(() => {
    const list = [...props.messages];
    if (form.processing && lastSent.value) {
        list.push({ id: 'pending-u', role: 'user', content: lastSent.value, at: '' });
        list.push({ id: 'pending-a', role: 'assistant', content: '__thinking__', at: '' });
    }
    return list;
});

function scrollDown() {
    nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; });
}
onMounted(scrollDown);
watch(view, scrollDown, { deep: true });

function send() {
    if (!form.message.trim() || form.processing) return;
    lastSent.value = form.message;
    form.transform((d) => ({ ...d, conversation_id: props.conversation.id }))
        .post('/chat/send', { preserveScroll: true, onSuccess: () => { form.reset('message'); } });
}
function newChat() { router.post('/chat/new'); }

const catLabel = (m) => ({
    task: '已觸發任務', new_domain: '已新增領域', configure_notify: '已設定通知',
}[m?.category]);
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
                    <Link
                        v-for="c in conversations" :key="c.id"
                        :href="`/chat?c=${c.id}`"
                        class="block truncate rounded-lg px-3 py-2 text-xs"
                        :class="c.id === conversation.id ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5'"
                    >{{ c.title }}</Link>
                </div>
            </aside>

            <!-- 對話主區 -->
            <main class="flex min-w-0 flex-1 flex-col">
                <header class="flex items-center gap-2 border-b border-white/10 px-5 py-3">
                    <span class="ooda-dot"></span>
                    <h1 class="font-semibold text-white">指揮 AI</h1>
                    <span class="text-xs text-slate-500">· 對話形式，自動記住上下文與判斷意圖</span>
                </header>

                <!-- 訊息區（可捲動） -->
                <div ref="scroller" class="flex-1 space-y-4 overflow-y-auto px-5 py-6">
                    <div v-if="!view.length" class="mx-auto max-w-md pt-10 text-center text-sm text-slate-500">
                        你好 👋 我是 PAI 指揮 AI。用白話跟我說，我會自動判斷要「執行任務、新增領域、還是設定通知」。<br />
                        例如：「host-7 好像中毒了幫我看」、「監控資料庫慢查詢」、「設定我的 Telegram 通知」。
                    </div>

                    <div v-for="m in view" :key="m.id" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[80%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed"
                             :class="m.role === 'user' ? 'bg-indigo-600 text-white' : 'border border-white/10 bg-white/5 text-slate-200'">
                            <span v-if="m.content === '__thinking__'" class="inline-flex gap-1">
                                <span class="dot"></span><span class="dot" style="animation-delay:.2s"></span><span class="dot" style="animation-delay:.4s"></span>
                            </span>
                            <template v-else>
                                <div class="whitespace-pre-wrap">{{ m.content }}</div>
                                <div v-if="catLabel(m.meta)" class="mt-1 text-[10px] text-emerald-300/70">⚙ {{ catLabel(m.meta) }}</div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- 對話框（獨立分離於底部） -->
                <div class="border-t border-white/10 bg-slate-950/60 px-5 py-3">
                    <form class="flex items-end gap-2" @submit.prevent="send">
                        <textarea
                            v-model="form.message"
                            rows="1"
                            placeholder="跟 AI 說一句話…（Enter 送出，Shift+Enter 換行）"
                            class="inp flex-1 resize-none"
                            @keydown.enter.exact.prevent="send"
                        ></textarea>
                        <button type="submit" :disabled="form.processing || !form.message.trim()" class="btn-send">
                            {{ form.processing ? '…' : '送出' }}
                        </button>
                    </form>
                    <p class="mt-1 text-[10px] text-slate-600">AI 會自動串接前文；本機模型回覆約需數十秒。</p>
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
@keyframes blink { 0%,100% { opacity: .2 } 50% { opacity: 1 } }
</style>
