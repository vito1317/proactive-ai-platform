<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, reactive, onMounted } from 'vue';

const props = defineProps({
    automations: { type: Array, default: () => [] },
    thoughts: { type: Array, default: () => [] },
    builtins: { type: Array, default: () => [] },
});

// 這些是與手機共用的 JSON API（非 Inertia）→ 用 fetch 呼叫後再 reload 頁面
async function apiPost(url, body) {
    await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body || {}),
    });
    router.reload({ preserveScroll: true });
}

function toggleBuiltin(b) {
    apiPost('/api/automations/builtin', { key: b.key, enabled: !b.enabled });
}
function toggle(a) {
    apiPost(`/api/automations/${a.id}/toggle`, { action: a.enabled ? 'disable' : 'enable' });
}
function remove(a) {
    if (!confirm(`刪除自動化「${a.name}」？`)) return;
    apiPost(`/api/automations/${a.id}/toggle`, { action: 'delete' });
}

// 自動停止編輯器：哪一條正在編輯 + 暫存輸入
const editing = ref(null);
const form = reactive({ expires_at: '', max_runs: '' });
function openLimit(a) {
    editing.value = editing.value === a.id ? null : a.id;
    form.expires_at = a.expires_at || '';
    form.max_runs = a.max_runs ?? '';
}
function saveLimit(a) {
    apiPost(`/api/automations/${a.id}/toggle`, {
        action: 'set_limit',
        expires_at: form.expires_at || null,
        max_runs: form.max_runs === '' ? null : Number(form.max_runs),
    });
    editing.value = null;
}
function clearLimit(a) {
    apiPost(`/api/automations/${a.id}/toggle`, { action: 'set_limit', expires_at: null, max_runs: null });
    editing.value = null;
}

// 匯出/匯入（市集基礎）：匯出下載 JSON 分享檔；匯入貼上 JSON 一鍵安裝（預設停用）
function exportAuto(a) {
    window.open(`/api/automations/${a.id}/export`, '_blank');
}
const importText = ref('');
const importMsg = ref('');
async function importAuto() {
    importMsg.value = '';
    try {
        const r = await fetch('/api/automations/import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ json: importText.value }),
        });
        const d = await r.json();
        if (d.ok) {
            importText.value = '';
            importMsg.value = `✅ 已匯入「${d.name}」（預設停用，檢視後再啟用）`;
            router.reload({ preserveScroll: true });
        } else importMsg.value = `❌ ${d.error || '匯入失敗'}`;
    } catch { importMsg.value = '❌ 匯入失敗'; }
}

// 明天預演：未來 24h 的觸發時間軸（自動化＋定時任務＋簡報＋守望）
const preview = ref(null);
onMounted(async () => {
    try {
        const r = await fetch('/api/automations/preview', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        preview.value = (await r.json()).items || [];
    } catch { preview.value = []; }
});
</script>

<template>
    <Head title="自動化 · AI 主動思考" />
    <div class="min-h-screen bg-slate-950 px-4 py-6 text-slate-100">
        <div class="mx-auto max-w-3xl space-y-6">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold">🤖 自動化 · AI 主動思考</h1>
                <Link href="/" class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-slate-300 hover:text-white">← 返回</Link>
            </div>

            <!-- 內建自動化開關 -->
            <section class="glass rounded-2xl border border-white/10 bg-white/5 p-5">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-300">內建功能（可開關）</h2>
                <ul class="space-y-2">
                    <li v-for="b in builtins" :key="b.key"
                        class="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                        <div class="min-w-0">
                            <div class="font-medium">{{ b.name }}</div>
                            <div class="mt-0.5 text-xs text-slate-400">{{ b.desc }}</div>
                        </div>
                        <button @click="toggleBuiltin(b)"
                            class="shrink-0 rounded-full px-3 py-1 text-xs transition"
                            :class="b.enabled ? 'bg-emerald-500/25 text-emerald-300' : 'bg-white/10 text-slate-400'">
                            {{ b.enabled ? '已啟用' : '已停用' }}
                        </button>
                    </li>
                </ul>
            </section>

            <!-- 明天預演：未來 24h 觸發時間軸 -->
            <section class="glass rounded-2xl border border-white/10 bg-white/5 p-5">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-300">🔮 明天預演（未來 24 小時）</h2>
                <p v-if="preview === null" class="text-sm text-slate-500">載入中…</p>
                <p v-else-if="!preview.length" class="text-sm text-slate-500">
                    未來 24 小時沒有排定會觸發的東西。建了自動化之後，這裡會直接演給你看「明天幾點會發生什麼」。
                </p>
                <ol v-else class="relative ml-2 space-y-3 border-l border-white/10 pl-5">
                    <li v-for="(p, i) in preview" :key="i" class="relative">
                        <span class="absolute -left-[27px] top-0.5 text-sm">{{ p.icon }}</span>
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-xs font-semibold text-cyan-300">{{ p.time }}</span>
                            <span class="text-sm font-medium text-slate-100">{{ p.title }}</span>
                        </div>
                        <div v-if="p.detail" class="text-xs text-slate-400">{{ p.detail }}</div>
                        <div v-if="p.note" class="text-[11px] text-amber-300/70">{{ p.note }}</div>
                    </li>
                </ol>
            </section>

            <!-- 自動化流程列表（AI 或你建立的） -->
            <section class="glass rounded-2xl border border-white/10 bg-white/5 p-5">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-300">已建立的功能 / 流程</h2>
                <p v-if="!automations.length" class="text-sm text-slate-500">
                    還沒有自動化流程。跟 AI 說「幫我建立一個每天早上提醒上班的流程」，它會自己建一條到這裡。
                </p>
                <ul class="space-y-2">
                    <li v-for="a in automations" :key="a.id"
                        class="rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span :class="a.enabled ? 'text-emerald-400' : 'text-slate-600'">●</span>
                                    <span class="truncate font-medium">{{ a.name }}</span>
                                    <span v-if="a.source === 'ai'" class="rounded bg-fuchsia-500/20 px-1.5 text-[10px] text-fuchsia-300">AI 建立</span>
                                </div>
                                <div class="mt-0.5 truncate text-xs text-slate-400">⏱ {{ a.trigger }} · 動作：{{ a.actions || '—' }}</div>
                                <div v-if="a.auto_stop" class="mt-0.5 truncate text-xs text-amber-300/80">🛑 自動停止：{{ a.auto_stop }}</div>
                                <div v-else class="mt-0.5 text-xs text-slate-600">♾ 長期執行（未設自動停止）</div>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <button @click="exportAuto(a)" class="rounded-lg border border-white/10 px-2.5 py-1 text-xs hover:bg-white/10" title="下載分享檔">匯出</button>
                                <button @click="openLimit(a)" class="rounded-lg border border-white/10 px-2.5 py-1 text-xs hover:bg-white/10">截止</button>
                                <button @click="toggle(a)" class="rounded-lg border border-white/10 px-2.5 py-1 text-xs hover:bg-white/10">
                                    {{ a.enabled ? '停用' : '啟用' }}
                                </button>
                                <button @click="remove(a)" class="rounded-lg border border-rose-500/30 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10">刪除</button>
                            </div>
                        </div>

                        <!-- 自動停止設定 -->
                        <div v-if="editing === a.id" class="mt-3 space-y-2 rounded-lg border border-white/10 bg-slate-950/60 p-3">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <label class="text-slate-400">截止時間</label>
                                <input v-model="form.expires_at" type="datetime-local"
                                    class="rounded border border-white/10 bg-slate-900 px-2 py-1 text-slate-100" />
                                <label class="ml-2 text-slate-400">最多執行</label>
                                <input v-model="form.max_runs" type="number" min="1" placeholder="次數"
                                    class="w-20 rounded border border-white/10 bg-slate-900 px-2 py-1 text-slate-100" />
                                <span class="text-slate-500">次</span>
                            </div>
                            <p class="text-[11px] text-slate-500">到截止時間、或跑滿次數後會自動停用。兩者皆可留空＝長期執行。</p>
                            <div class="flex gap-2">
                                <button @click="saveLimit(a)" class="rounded-lg bg-emerald-500/25 px-3 py-1 text-xs text-emerald-200 hover:bg-emerald-500/35">儲存</button>
                                <button @click="clearLimit(a)" class="rounded-lg border border-white/10 px-3 py-1 text-xs text-slate-300 hover:bg-white/10">清除（改長期）</button>
                                <button @click="editing = null" class="rounded-lg border border-white/10 px-3 py-1 text-xs text-slate-400 hover:bg-white/10">取消</button>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- 匯入分享的自動化（市集基礎：匯出檔可分享給別人一鍵安裝） -->
            <section class="glass rounded-2xl border border-white/10 bg-white/5 p-5">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-300">📦 匯入分享的自動化</h2>
                <p class="mb-2 text-xs text-slate-500">
                    把別人「匯出」的 JSON 貼進來即可安裝；匯入後預設停用，檢視內容沒問題再啟用。
                </p>
                <textarea v-model="importText" rows="3" placeholder='{"pai_automation":1,"name":"…","spec":{…}}'
                    class="w-full rounded-lg border border-white/10 bg-slate-900 p-2 text-xs text-slate-100"></textarea>
                <div class="mt-2 flex items-center gap-3">
                    <button @click="importAuto" :disabled="!importText.trim()"
                        class="rounded-lg bg-cyan-500/25 px-3 py-1 text-xs text-cyan-200 hover:bg-cyan-500/35 disabled:opacity-40">匯入</button>
                    <span class="text-xs" :class="importMsg.startsWith('✅') ? 'text-emerald-300' : 'text-rose-300'">{{ importMsg }}</span>
                </div>
            </section>

            <!-- AI 主動思考記錄 -->
            <section class="glass rounded-2xl border border-white/10 bg-white/5 p-5">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-300">🧠 AI 主動思考記錄</h2>
                <p v-if="!thoughts.length" class="text-sm text-slate-500">
                    還沒有思考記錄。到「設定 → 🤖 自動化 / 主動」開啟「AI 主動思考」後，它會定期自己想、結果顯示在這。
                </p>
                <ul class="space-y-2">
                    <li v-for="(t, i) in thoughts" :key="i"
                        class="rounded-xl border px-4 py-2.5 text-sm"
                        :class="t.acted ? 'border-amber-500/30 bg-amber-500/10' : 'border-white/5 bg-slate-900/40'">
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            <span>{{ t.at }}</span>
                            <span v-if="t.acted" class="rounded bg-amber-500/20 px-1.5 text-amber-300">採取行動</span>
                            <span v-else class="text-slate-600">無動作</span>
                        </div>
                        <div class="mt-1 text-slate-200">{{ t.text }}</div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
