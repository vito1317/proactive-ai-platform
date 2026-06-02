<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    domains: { type: Array, default: () => [] },
    preview: { type: Object, default: null },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const genForm = useForm({ description: '' });
function generate() {
    if (!genForm.description.trim()) return;
    genForm.post('/packs/generate', { preserveScroll: true });
}

const saveForm = useForm({ yaml: '' });
function save() {
    saveForm.yaml = props.preview?.yaml || '';
    saveForm.post('/packs/save', { preserveScroll: true });
}

const examples = [
    '監控資料庫慢查詢，超過門檻就告警並建議加索引',
    '監聽客服信箱，分類工單並對高優先級草擬回覆',
    '監控雲端成本，異常飆升時分析並提出節流建議',
];
</script>

<template>
    <Head title="領域包" />

    <div class="console">
        <div class="bg-glow"></div>
        <div class="relative z-10 mx-auto max-w-4xl px-6 py-6">
            <header class="flex items-center justify-between pb-6">
                <div>
                    <h1 class="text-2xl font-bold text-white">🧩 領域包</h1>
                    <p class="text-sm text-slate-400">用自然語言描述，AI 生成並啟用一個新領域（免寫程式）</p>
                </div>
                <Link href="/" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300 hover:text-white">← 回中控台</Link>
            </header>

            <transition name="fade">
                <div v-if="flash.success" class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">{{ flash.success }}</div>
            </transition>
            <transition name="fade">
                <div v-if="flash.error" class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ flash.error }}</div>
            </transition>

            <!-- 已載入領域 -->
            <section class="glass mb-6 p-5">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-300">已載入領域（{{ domains.length }}）</h2>
                <div class="flex flex-wrap gap-2">
                    <span v-for="d in domains" :key="d.domain" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs">
                        <span class="font-mono text-indigo-300">{{ d.domain }}</span>
                        <span class="ml-2 text-slate-500">{{ d.autonomy }} · {{ d.agents.length }} agents</span>
                    </span>
                </div>
            </section>

            <!-- 生成 -->
            <section class="glass p-5">
                <h2 class="font-semibold text-white">用自然語言新增領域包</h2>
                <form class="mt-3 space-y-3" @submit.prevent="generate">
                    <textarea v-model="genForm.description" rows="3" class="inp" placeholder="例如：監控日誌偵測錯誤並自動修復，破壞性動作需人類核准…"></textarea>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="(ex, i) in examples" :key="i" type="button"
                                class="rounded-lg border border-white/5 bg-white/5 px-2.5 py-1 text-xs text-slate-300 hover:text-white"
                                @click="genForm.description = ex">{{ ex }}</button>
                    </div>
                    <button type="submit" :disabled="genForm.processing || !genForm.description.trim()" class="btn-primary">
                        {{ genForm.processing ? 'AI 生成中…（約 20-40 秒）' : '✨ 生成領域包' }}
                    </button>
                </form>
            </section>

            <!-- 預覽 -->
            <section v-if="preview" class="glass mt-6 p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold text-white">預覽</h2>
                    <span class="rounded px-2 py-0.5 text-xs" :class="preview.valid ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/25 text-red-300'">
                        {{ preview.valid ? '✓ 通過 schema 驗證' : '✗ 驗證未過' }}
                    </span>
                </div>
                <ul v-if="!preview.valid" class="mb-3 space-y-1 text-xs text-red-300">
                    <li v-for="(e, i) in preview.errors" :key="i">• {{ e }}</li>
                </ul>
                <pre v-if="preview.yaml" class="max-h-96 overflow-auto rounded-lg border border-white/10 bg-black/40 p-3 text-xs text-slate-200">{{ preview.yaml }}</pre>
                <button v-if="preview.valid" :disabled="saveForm.processing" class="btn-primary mt-3" @click="save">
                    {{ saveForm.processing ? '儲存中…' : '💾 儲存並啟用' }}
                </button>
            </section>
        </div>
    </div>
</template>

<style scoped>
.console { position: relative; min-height: 100vh; background: #020617; color: #e2e8f0; overflow: hidden; }
.bg-glow { position: absolute; inset: 0; pointer-events: none;
    background: radial-gradient(600px circle at 20% 0%, rgba(99,102,241,0.16), transparent 45%), radial-gradient(600px circle at 85% 20%, rgba(34,211,238,0.1), transparent 45%); }
.glass { border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); background: rgba(15,23,42,0.55); backdrop-filter: blur(12px); }
.inp { width: 100%; border-radius: 0.5rem; border: 1px solid rgba(255,255,255,0.1); background: rgba(2,6,23,0.6); color: #e2e8f0; padding: 0.5rem 0.65rem; font-size: 0.85rem; }
.inp:focus { outline: none; border-color: #6366f1; }
.btn-primary { border-radius: 0.5rem; background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff; padding: 0.55rem 1rem; font-weight: 600; font-size: 0.85rem; }
.btn-primary:disabled { opacity: 0.5; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
</style>
