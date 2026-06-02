<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    fields: { type: Array, default: () => [] },
    domains: { type: Array, default: () => [] },
    autonomyLevels: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const groups = computed(() => [...new Set(props.fields.map((f) => f.group))]);
const fieldsIn = (g) => props.fields.filter((f) => f.group === g);

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
                <Link href="/" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300 hover:text-white">← 回中控台</Link>
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

            <form class="space-y-6" @submit.prevent="save">
                <section v-for="g in groups" :key="g" class="glass p-5">
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
                                <input v-else type="text" v-model="form.settings[f.key]" class="inp" />
                            </div>
                        </div>
                    </div>
                </section>

                <section class="glass p-5">
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
