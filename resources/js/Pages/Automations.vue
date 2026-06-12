<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    automations: { type: Array, default: () => [] },
    thoughts: { type: Array, default: () => [] },
    builtins: { type: Array, default: () => [] },
});

function toggleBuiltin(b) {
    router.post('/api/automations/builtin', { key: b.key, enabled: !b.enabled }, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['builtins'] }),
    });
}

function toggle(a) {
    router.post(`/api/automations/${a.id}/toggle`, { action: a.enabled ? 'disable' : 'enable' }, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['automations'] }),
    });
}
function remove(a) {
    if (!confirm(`刪除自動化「${a.name}」？`)) return;
    router.post(`/api/automations/${a.id}/toggle`, { action: 'delete' }, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['automations'] }),
    });
}
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

            <!-- 自動化流程列表（AI 或你建立的） -->
            <section class="glass rounded-2xl border border-white/10 bg-white/5 p-5">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-300">已建立的功能 / 流程</h2>
                <p v-if="!automations.length" class="text-sm text-slate-500">
                    還沒有自動化流程。跟 AI 說「幫我建立一個每天早上提醒上班的流程」，它會自己建一條到這裡。
                </p>
                <ul class="space-y-2">
                    <li v-for="a in automations" :key="a.id"
                        class="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span :class="a.enabled ? 'text-emerald-400' : 'text-slate-600'">●</span>
                                <span class="truncate font-medium">{{ a.name }}</span>
                                <span v-if="a.source === 'ai'" class="rounded bg-fuchsia-500/20 px-1.5 text-[10px] text-fuchsia-300">AI 建立</span>
                            </div>
                            <div class="mt-0.5 truncate text-xs text-slate-400">⏱ {{ a.trigger }} · 動作：{{ a.actions || '—' }}</div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button @click="toggle(a)" class="rounded-lg border border-white/10 px-2.5 py-1 text-xs hover:bg-white/10">
                                {{ a.enabled ? '停用' : '啟用' }}
                            </button>
                            <button @click="remove(a)" class="rounded-lg border border-rose-500/30 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10">刪除</button>
                        </div>
                    </li>
                </ul>
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
