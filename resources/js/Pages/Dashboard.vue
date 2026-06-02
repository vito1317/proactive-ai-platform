<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    platform: { type: String, default: 'PAI' },
    domains: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="主控台" />

    <main class="mx-auto max-w-3xl px-6 py-16">
        <h1 class="text-3xl font-semibold tracking-tight">
            {{ platform }} 主動式 AI 平台
        </h1>
        <p class="mt-2 text-slate-500">
            Laravel 13 + Inertia + Vue 3 骨架已就緒。
        </p>

        <section class="mt-8">
            <h2 class="text-sm font-medium uppercase tracking-wide text-slate-400">
                已載入領域包
            </h2>
            <ul v-if="domains.length" class="mt-3 space-y-2">
                <li
                    v-for="d in domains"
                    :key="d.domain"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3"
                >
                    <span class="font-mono text-indigo-600">{{ d.domain }}</span>
                    <span class="ml-2 text-slate-600">{{ d.description }}</span>
                    <span class="ml-2 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                        autonomy: {{ d.autonomy }}
                    </span>
                </li>
            </ul>
            <p v-else class="mt-3 text-slate-400">（尚未載入領域包）</p>
        </section>
    </main>
</template>
