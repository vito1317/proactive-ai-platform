<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    profiles: { type: Array, default: () => [] },
    active: { type: String, default: '' },
    tools: { type: Array, default: () => [] },
});
const page = usePage();
const flash = computed(() => page.props.flash || {});

// 本地可編輯副本；tools 'all' → allMode=true，否則 array
const list = ref(props.profiles.map((p) => ({
    name: p.name || '',
    soul: p.soul || '',
    constraints: p.constraints || '',
    allMode: !Array.isArray(p.tools),
    tools: Array.isArray(p.tools) ? [...p.tools] : [],
})));
const activeName = ref(props.active);

function addProfile() {
    list.value.push({ name: '新人格', soul: '', constraints: '', allMode: true, tools: [] });
}
function removeProfile(i) {
    if (confirm(`刪除人格「${list.value[i].name}」？`)) list.value.splice(i, 1);
}
function toggleTool(p, t) {
    const i = p.tools.indexOf(t);
    if (i >= 0) p.tools.splice(i, 1); else p.tools.push(t);
}
function save() {
    const profiles = list.value.map((p) => ({
        name: p.name, soul: p.soul, constraints: p.constraints,
        tools: p.allMode ? 'all' : p.tools,
    }));
    router.post('/agent/profiles', { profiles, active: activeName.value }, { preserveScroll: true });
}
function activate(name) {
    activeName.value = name;
    router.post('/agent/profiles/activate', { name }, { preserveScroll: true });
}
</script>

<template>
    <Head title="人格 / 模式" />
    <div class="min-h-screen bg-slate-950 text-slate-200">
        <div class="mx-auto max-w-3xl px-5 py-8">
            <header class="flex items-center justify-between pb-6">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-white">🎭 人格 / 模式</h1>
                    <p class="text-sm text-slate-400">每個人格＝身分語氣(SOUL) + 可用工具 + 行為約束，可隨時切換；語音也能說「切換成X人格」。</p>
                </div>
                <Link href="/settings" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300 hover:text-white">← 設定</Link>
            </header>

            <div v-if="flash.success" class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash.error" class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">{{ flash.error }}</div>

            <div class="mb-4 flex items-center justify-between">
                <span class="text-sm text-slate-400">目前啟用：<span class="font-bold text-emerald-300">{{ activeName || '預設' }}</span></span>
                <div class="flex gap-2">
                    <button @click="addProfile" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-200 hover:bg-white/10">＋ 新增人格</button>
                    <button @click="save" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">儲存全部</button>
                </div>
            </div>

            <section class="space-y-4">
                <div v-for="(p, i) in list" :key="i" class="rounded-xl border border-white/10 bg-white/5 p-4"
                     :class="{ 'ring-1 ring-emerald-400/50': p.name === activeName }">
                    <div class="mb-3 flex items-center gap-2">
                        <input v-model="p.name" class="inp flex-1 font-semibold" placeholder="人格名稱（如：管家 / 研究模式）" />
                        <button @click="activate(p.name)" class="rounded-lg px-3 py-1.5 text-sm"
                            :class="p.name === activeName ? 'bg-emerald-600 text-white' : 'border border-white/10 bg-white/5 text-slate-300 hover:bg-white/10'">
                            {{ p.name === activeName ? '✓ 啟用中' : '啟用' }}
                        </button>
                        <button @click="removeProfile(i)" class="rounded-lg border border-red-400/30 bg-red-500/10 px-2.5 py-1.5 text-sm text-red-300 hover:bg-red-500/20">刪除</button>
                    </div>

                    <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-400">人格 / 身分（SOUL）</label>
                    <textarea v-model="p.soul" rows="3" class="inp mb-3 w-full resize-y" placeholder="例：你是沉穩的英式管家，講話簡潔有禮，稱呼我為主人。"></textarea>

                    <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-400">行為約束</label>
                    <textarea v-model="p.constraints" rows="2" class="inp mb-3 w-full resize-y" placeholder="例：回答不超過三句；不確定就說不知道，不要編造。"></textarea>

                    <label class="mb-1 flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-400">
                        可用工具
                        <label class="ml-2 flex items-center gap-1 normal-case text-slate-300"><input type="checkbox" v-model="p.allMode" /> 全部工具</label>
                    </label>
                    <div v-if="!p.allMode" class="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto rounded-lg border border-white/5 bg-black/20 p-2">
                        <button v-for="t in tools" :key="t" type="button" @click="toggleTool(p, t)"
                            class="rounded px-2 py-0.5 text-xs font-mono"
                            :class="p.tools.includes(t) ? 'bg-indigo-600/50 text-indigo-100' : 'bg-white/5 text-slate-400 hover:bg-white/10'">{{ t }}</button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
