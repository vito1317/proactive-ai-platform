<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({
    servers: { type: Array, default: () => [] },
    isAdmin: { type: Boolean, default: false },
});
const page = usePage();
const flash = computed(() => page.props.flash || {});
const testResult = reactive({});

const form = useForm({ name: '', url: '', secret: '' });
function add() {
    form.post('/agent/mcp', { preserveScroll: true, onSuccess: () => form.reset() });
}
async function test(s) {
    testResult[s.id] = '測試中…';
    try {
        const r = await fetch(`/agent/mcp/${s.id}/test`);
        const j = await r.json();
        testResult[s.id] = j.ok ? `✓ 連線正常（${(j.tools || []).length} 工具）` : `✗ ${j.message || '連線失敗'}`;
    } catch (e) { testResult[s.id] = '✗ ' + e; }
}
function remove(s) {
    if (confirm(`移除 MCP「${s.name}」？`)) router.delete(`/agent/mcp/${s.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="MCP 管理" />
    <div class="min-h-screen bg-slate-950 text-slate-200">
        <div class="mx-auto max-w-3xl px-5 py-8">
            <header class="flex items-center justify-between pb-6">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-white">🔌 MCP 伺服器</h1>
                    <p class="text-sm text-slate-400">接入外部 MCP 工具（HTTP/Streamable）。每個帳號管理自己的；接入的工具會自動出現在 AI 工具箱。</p>
                </div>
                <Link href="/settings" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300 hover:text-white">← 設定</Link>
            </header>

            <div v-if="flash.success" class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash.error" class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">{{ flash.error }}</div>

            <section class="mb-6 rounded-xl border border-white/10 bg-white/5 p-4">
                <h2 class="mb-3 text-sm font-bold uppercase tracking-wide text-indigo-300">＋ 接入 MCP 伺服器</h2>
                <div class="grid gap-2 sm:grid-cols-4">
                    <input v-model="form.name" placeholder="代號(英數)" class="inp" />
                    <input v-model="form.url" placeholder="MCP 端點 URL" class="inp sm:col-span-2" />
                    <input v-model="form.secret" placeholder="Bearer Token(可空)" class="inp" />
                </div>
                <button @click="add" :disabled="form.processing" class="mt-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">接入</button>
            </section>

            <section class="space-y-2">
                <div v-for="s in servers" :key="s.id" class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-white">{{ s.name }}
                                <span class="ml-1 text-[11px]" :class="s.enabled ? 'text-emerald-400' : 'text-red-400'">{{ s.enabled ? '● 啟用' : '○ 停用' }}</span>
                                <span v-if="s.reverse" class="ml-1 text-[11px] text-sky-400">反向節點</span>
                                <span class="ml-1 text-[11px] text-slate-500">{{ s.tools }} 工具</span>
                            </div>
                            <div class="truncate text-xs text-slate-500">{{ s.url }}</div>
                            <div v-if="testResult[s.id]" class="mt-1 text-xs text-slate-300">{{ testResult[s.id] }}</div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button v-if="!s.reverse" @click="test(s)" class="rounded-lg border border-white/10 bg-white/5 px-2.5 py-1 text-xs text-slate-300 hover:bg-white/10">測試</button>
                            <button v-if="s.mine || isAdmin" @click="remove(s)" class="rounded-lg border border-red-400/30 bg-red-500/10 px-2.5 py-1 text-xs text-red-300 hover:bg-red-500/20">移除</button>
                        </div>
                    </div>
                </div>
                <p v-if="!servers.length" class="text-sm text-slate-500">還沒有可存取的 MCP 伺服器。上面接入一個，或請管理員授權裝置給你。</p>
            </section>
        </div>
    </div>
</template>
