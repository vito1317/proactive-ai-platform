<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    fields: { type: Array, default: () => [] },
    domains: { type: Array, default: () => [] },
    autonomyLevels: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const fieldsIn = (g) => props.fields.filter((f) => f.group === g);
// 大分類（設定分區）→ 分頁；每個分類底下再列各 group
const categories = computed(() => [...new Set(props.fields.map((f) => f.category || '其他'))]);
const groupsIn = (cat) => [...new Set(props.fields.filter((f) => (f.category || '其他') === cat).map((f) => f.group))];
const activeCat = ref('');
const currentCat = computed(() => activeCat.value || categories.value[0] || '');

const form = useForm({
    settings: Object.fromEntries(props.fields.map((f) => [f.key, f.value])),
    autonomy: Object.fromEntries(props.domains.map((d) => [d.domain, d.effective])),
});

function save() {
    form.post('/settings', { preserveScroll: true });
}

// AI 引導設定通知（TG/LINE/webhook）
const assistForm = useForm({ message: '' });
const notifyExamples = [
    '我的 Telegram bot token 是 123456:ABC-DEF，chat id 是 987654321',
    '幫我設定 LINE 推播，channel access token 是 xxxx，推給 Uabcdef',
    '我想用 Slack webhook：https://hooks.slack.com/services/T00/B00/xxxx',
];
function assist(text) {
    if (text) assistForm.message = text;
    if (!assistForm.message.trim()) return;
    assistForm.post('/notify/assist', { preserveScroll: true, onSuccess: () => assistForm.reset('message') });
}
function testNotify() {
    router.post('/notify/test', {}, { preserveScroll: true });
}

// 通知頻道（TG/LINE）：列出 / 刷新 / 選取
const channels = ref({ telegram: [], line: [], webhook_url: {} });
async function loadChannels() {
    try {
        const r = await fetch('/notify/channels', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (r.ok) channels.value = await r.json();
    } catch (e) { /* ignore */ }
}
onMounted(loadChannels);
function selectChannel(platform, id) {
    router.post('/notify/channels/select', { platform, id }, { preserveScroll: true, onSuccess: loadChannels });
}
function refreshTg() {
    router.post('/notify/channels/refresh', {}, { preserveScroll: true, onSuccess: loadChannels });
}
function chLabel(c) {
    const t = c.title || c.id;
    return c.type && c.type !== 'private' && c.type !== 'user' ? `${t}（${c.type}）` : t;
}
</script>

<template>
    <Head title="後台設定" />

    <div class="console">
        <div class="bg-glow"></div>
        <div class="relative z-10 mx-auto max-w-3xl px-6 py-6">
            <header class="flex items-center justify-between pb-6">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-white">⚙ 後台設定</h1>
                    <p class="text-sm text-slate-400">所有參數即時生效（覆寫預設，不必重啟）</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link href="/agent/profiles" class="rounded-lg border border-fuchsia-400/30 bg-fuchsia-500/10 px-3 py-1.5 text-sm text-fuchsia-300 hover:bg-fuchsia-500/20">🎭 人格/模式</Link>
                    <Link href="/agent/mcp" class="rounded-lg border border-sky-400/30 bg-sky-500/10 px-3 py-1.5 text-sm text-sky-300 hover:bg-sky-500/20">🔌 MCP</Link>
                    <Link v-if="page.props.auth?.user?.is_admin" href="/admin/accounts" class="rounded-lg border border-amber-400/30 bg-amber-500/10 px-3 py-1.5 text-sm text-amber-300 hover:bg-amber-500/20">👥 帳號管理</Link>
                    <Link href="/" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300 hover:text-white">← 回中控台</Link>
                </div>
            </header>

            <transition name="fade">
                <div v-if="flash.success" class="mb-5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    {{ flash.success }}
                </div>
            </transition>
            <transition name="fade">
                <div v-if="flash.error" class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ flash.error }}</div>
            </transition>

            <!-- AI 引導設定通知 -->
            <section class="glass mb-6 p-5">
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 font-semibold text-white">🤖 用 AI 引導設定通知（Telegram / LINE / Webhook）</h2>
                    <button type="button" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1 text-xs text-slate-300 hover:text-white" @click="testNotify">測試通知</button>
                </div>
                <p class="mt-1 text-xs text-slate-400">用白話貼上你的 token / 對象，AI 會自動解析、填好設定並發測試訊息。</p>
                <form class="mt-3 space-y-2" @submit.prevent="assist()">
                    <textarea v-model="assistForm.message" rows="2" class="inp" placeholder="例如：我的 Telegram bot token 是 …，chat id 是 …"></textarea>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="(ex, i) in notifyExamples" :key="i" type="button"
                                class="rounded-lg border border-white/5 bg-white/5 px-2.5 py-1 text-xs text-slate-400 hover:text-white"
                                @click="assistForm.message = ex">{{ ex }}</button>
                    </div>
                    <button type="submit" :disabled="assistForm.processing || !assistForm.message.trim()" class="btn-primary">
                        {{ assistForm.processing ? 'AI 解析中…' : '✨ 讓 AI 設定' }}
                    </button>
                </form>
            </section>

            <!-- 通知頻道（TG / LINE）：選取 + 查看 -->
            <section class="glass mb-6 p-5">
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 font-semibold text-white">📡 通知頻道（Channels）</h2>
                    <button type="button" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1 text-xs text-slate-300 hover:text-white" @click="loadChannels">重新整理</button>
                </div>
                <p class="mt-1 text-xs text-slate-400">選取 bot 要推播 / 回覆的頻道。bot 收到訊息的對話會自動出現在這裡。</p>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <!-- Telegram -->
                    <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                        <div class="mb-2 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-sky-300">✈️ Telegram</h3>
                            <button type="button" class="rounded border border-white/10 bg-white/5 px-2 py-0.5 text-xs text-slate-300 hover:text-white" @click="refreshTg">刷新頻道</button>
                        </div>
                        <p v-if="!channels.telegram.length" class="text-xs text-slate-500">尚無頻道。請先在 Telegram 對 bot 傳一則訊息，再按「刷新頻道」。</p>
                        <ul class="space-y-1.5">
                            <li v-for="c in channels.telegram" :key="c.id">
                                <button type="button" class="flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-sm transition"
                                        :class="c.selected ? 'border-sky-400/60 bg-sky-400/10 text-white' : 'border-white/10 bg-white/[0.02] text-slate-300 hover:border-white/20'"
                                        @click="selectChannel('telegram', String(c.id))">
                                    <span class="truncate">{{ chLabel(c) }}<span class="ml-1 font-mono text-xs text-slate-500">{{ c.id }}</span></span>
                                    <span v-if="c.selected" class="shrink-0 text-xs text-sky-300">● 使用中</span>
                                </button>
                            </li>
                        </ul>
                        <p class="mt-3 break-all text-[11px] text-slate-600">Webhook：{{ channels.webhook_url.telegram }}（用 <code>php artisan pai:telegram-webhook set</code> 啟用雙向）</p>
                    </div>

                    <!-- LINE -->
                    <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                        <h3 class="mb-2 text-sm font-semibold text-emerald-300">💬 LINE</h3>
                        <p v-if="!channels.line.length" class="text-xs text-slate-500">尚無頻道。將 bot 加入聊天/群組並傳訊後會自動出現。</p>
                        <ul class="space-y-1.5">
                            <li v-for="c in channels.line" :key="c.id">
                                <button type="button" class="flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-sm transition"
                                        :class="c.selected ? 'border-emerald-400/60 bg-emerald-400/10 text-white' : 'border-white/10 bg-white/[0.02] text-slate-300 hover:border-white/20'"
                                        @click="selectChannel('line', String(c.id))">
                                    <span class="truncate">{{ chLabel(c) }}</span>
                                    <span v-if="c.selected" class="shrink-0 text-xs text-emerald-300">● 使用中</span>
                                </button>
                            </li>
                        </ul>
                        <p class="mt-3 break-all text-[11px] text-slate-600">Webhook：{{ channels.webhook_url.line }}（填入 LINE Developers Console，並設定上方 Channel Secret）</p>
                    </div>
                </div>
            </section>

            <form class="space-y-6" @submit.prevent="save">
                <!-- 設定分類分頁 -->
                <div class="flex flex-wrap gap-2">
                    <button v-for="c in categories" :key="c" type="button" @click="activeCat = c"
                            class="rounded-full px-4 py-1.5 text-sm transition"
                            :class="currentCat === c ? 'bg-indigo-500 text-white' : 'bg-white/5 text-slate-300 hover:bg-white/10'">
                        {{ c }}
                    </button>
                </div>

                <section v-for="g in groupsIn(currentCat)" :key="g" class="glass p-5">
                    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ g }}</h2>
                    <div class="space-y-4">
                        <div v-for="f in fieldsIn(g)" :key="f.key" class="flex items-center justify-between gap-4">
                            <label class="text-sm text-slate-300">
                                {{ f.label }}
                                <span class="block font-mono text-xs text-slate-600">{{ f.key }}</span>
                            </label>
                            <div class="w-48 shrink-0">
                                <input v-if="f.type === 'bool'" type="checkbox" v-model="form.settings[f.key]" class="h-5 w-5 rounded border-white/20 bg-slate-900" />
                                <input v-else-if="f.type === 'int' || f.type === 'number'" type="number"
                                       :step="f.step || (f.type === 'int' ? 1 : 'any')" :min="f.min" :max="f.max"
                                       v-model="form.settings[f.key]" class="inp" />
                                <input v-else-if="f.type === 'secret'" type="password" autocomplete="off"
                                       v-model="form.settings[f.key]" class="inp" placeholder="••••••••" />
                                <select v-else-if="f.type === 'select'" v-model="form.settings[f.key]" class="inp">
                                    <option v-for="o in (f.options || [])" :key="o.value" :value="o.value">{{ o.label }}</option>
                                </select>
                                <input v-else type="text" v-model="form.settings[f.key]" class="inp" />
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="currentCat === '🧠 核心 AI'" class="glass p-5">
                    <h2 class="mb-1 text-sm font-semibold uppercase tracking-wider text-slate-300">各領域自治階段 (Autonomy)</h2>
                    <p class="mb-4 text-xs text-slate-500">copilot：一切待核准 · supervisor：僅高風險待核准 · autopilot：邊界內全自主</p>
                    <div class="space-y-3">
                        <div v-for="d in domains" :key="d.domain" class="flex items-center justify-between gap-4">
                            <label class="text-sm">
                                <span class="font-mono text-indigo-300">{{ d.domain }}</span>
                                <span class="ml-2 text-xs text-slate-500">領域包預設：{{ d.default }}</span>
                            </label>
                            <select v-model="form.autonomy[d.domain]" class="inp w-48">
                                <option v-for="lv in autonomyLevels" :key="lv" :value="lv">{{ lv }}</option>
                            </select>
                        </div>
                    </div>
                </section>

                <button type="submit" :disabled="form.processing" class="btn-primary w-auto px-6">
                    {{ form.processing ? '儲存中…' : '💾 儲存設定' }}
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.console { position: relative; min-height: 100vh; background: #020617; color: #e2e8f0; overflow: hidden; }
.bg-glow {
    position: absolute; inset: 0; pointer-events: none;
    background:
        radial-gradient(600px circle at 15% 0%, rgba(99,102,241,0.18), transparent 45%),
        radial-gradient(700px circle at 85% 10%, rgba(34,211,238,0.12), transparent 45%);
}
.glass { border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); background: rgba(15,23,42,0.55); backdrop-filter: blur(12px); }
.inp { width: 100%; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.1); background: rgba(2,6,23,0.6); color: #e2e8f0; padding: 0.4rem 0.6rem; font-size: 0.85rem; }
.inp:focus { outline: none; border-color: #6366f1; }
.btn-primary { border-radius: 0.5rem; background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff; padding: 0.55rem 1rem; font-size: 0.9rem; font-weight: 600; }
.btn-primary:disabled { opacity: 0.5; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
</style>
