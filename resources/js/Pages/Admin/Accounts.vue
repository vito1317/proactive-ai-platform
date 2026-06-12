<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    users: { type: Array, default: () => [] },
    devices: { type: Array, default: () => [] },
    skills: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

// 建立新帳號
const createForm = useForm({ name: '', email: '', password: '', role: 'user' });
function createAccount() {
    createForm.post('/admin/accounts', { preserveScroll: true, onSuccess: () => createForm.reset() });
}

// 每個帳號的本地編輯狀態
const expanded = ref(null);
const editState = reactive({});
function ensureEdit(u) {
    if (!editState[u.id]) {
        editState[u.id] = {
            role: u.role, status: u.status,
            caps: { all_devices: !!u.caps.all_devices, all_skills: !!u.caps.all_skills, memory: u.caps.memory !== false, local: !!u.caps.local },
            notify: { tg_chat_id: u.notify?.tg_chat_id || '', line_to: u.notify?.line_to || '' },
            device_ids: [...u.device_ids],
            skills: [...u.skills],
            password: '',
        };
    }
    return editState[u.id];
}
function toggle(u) { expanded.value = expanded.value === u.id ? null : u.id; ensureEdit(u); }

function saveProfile(u) {
    const s = editState[u.id];
    router.post(`/admin/accounts/${u.id}`, { role: s.role, status: s.status, caps: s.caps, notify: s.notify }, { preserveScroll: true });
}
function saveDevices(u) {
    router.post(`/admin/accounts/${u.id}/devices`, { device_ids: editState[u.id].device_ids }, { preserveScroll: true });
}
function saveSkills(u) {
    router.post(`/admin/accounts/${u.id}/skills`, { skills: editState[u.id].skills }, { preserveScroll: true });
}
function resetPw(u) {
    const s = editState[u.id];
    if (!s.password) return;
    router.post(`/admin/accounts/${u.id}/password`, { password: s.password }, { preserveScroll: true, onSuccess: () => (s.password = '') });
}
// 產生此帳號的一次性配對 QR（手機掃 → 綁到這個帳號）
const pairData = reactive({});
async function makePair(u) {
    try {
        const r = await fetch('/api/gateway/pair-create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
            body: JSON.stringify({ user_id: u.id }),
        });
        pairData[u.id] = await r.json();
    } catch (e) { pairData[u.id] = { error: String(e) }; }
}
function removeUser(u) {
    if (confirm(`確定刪除帳號 ${u.email}？此帳號的授權會一併移除。`)) {
        router.delete(`/admin/accounts/${u.id}`, { preserveScroll: true });
    }
}
function toggleIn(arr, val) {
    const i = arr.indexOf(val);
    if (i >= 0) arr.splice(i, 1); else arr.push(val);
}
</script>

<template>
    <Head title="帳號管理" />
    <div class="min-h-screen bg-slate-950 text-slate-200">
        <div class="mx-auto max-w-5xl px-5 py-8">
            <header class="flex items-center justify-between pb-6">
                <h1 class="text-2xl font-bold tracking-tight text-white">👥 帳號管理 · 權限</h1>
                <Link href="/" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-300 hover:text-white">← 回中控台</Link>
            </header>

            <div v-if="flash.success" class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-300">{{ flash.success }}</div>
            <div v-if="flash.error" class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">{{ flash.error }}</div>

            <!-- 建立帳號 -->
            <section class="mb-6 rounded-xl border border-white/10 bg-white/5 p-4">
                <h2 class="mb-3 text-sm font-bold tracking-wide text-indigo-300 uppercase">＋ 建立帳號</h2>
                <div class="grid gap-2 sm:grid-cols-5">
                    <input v-model="createForm.name" placeholder="名稱" class="inp" />
                    <input v-model="createForm.email" type="email" placeholder="Email" class="inp" />
                    <input v-model="createForm.password" type="text" placeholder="密碼(≥6)" class="inp" />
                    <select v-model="createForm.role" class="inp"><option value="user">一般使用者</option><option value="admin">管理員</option></select>
                    <button @click="createAccount" :disabled="createForm.processing" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">建立</button>
                </div>
                <p v-if="createForm.errors.email" class="mt-1 text-xs text-red-400">{{ createForm.errors.email }}</p>
            </section>

            <!-- 帳號列表 -->
            <section class="space-y-3">
                <div v-for="u in users" :key="u.id" class="rounded-xl border border-white/10 bg-white/5">
                    <button @click="toggle(u)" class="flex w-full items-center justify-between px-4 py-3 text-left">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full" :class="u.role === 'admin' ? 'bg-amber-500/20 text-amber-300' : 'bg-indigo-500/20 text-indigo-300'">{{ u.role === 'admin' ? '★' : '◍' }}</span>
                            <div>
                                <div class="font-semibold text-white">{{ u.name }} <span class="ml-1 text-xs text-slate-400">{{ u.email }}</span></div>
                                <div class="text-xs text-slate-400">
                                    {{ u.role === 'admin' ? '管理員（全權）' : '一般使用者' }} ·
                                    <span :class="u.status === 'active' ? 'text-emerald-400' : 'text-red-400'">{{ u.status === 'active' ? '啟用' : '停用' }}</span>
                                    <template v-if="u.role !== 'admin'"> · 裝置 {{ u.caps.all_devices ? '全部' : u.device_ids.length }} · skills {{ u.caps.all_skills ? '全部' : u.skills.length }}</template>
                                </div>
                            </div>
                        </div>
                        <span class="text-slate-500">{{ expanded === u.id ? '▲' : '▼' }}</span>
                    </button>

                    <div v-if="expanded === u.id" class="border-t border-white/10 p-4 space-y-5">
                        <!-- 角色 / 狀態 / 能力 -->
                        <div>
                            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">角色 · 狀態 · 能力</div>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <select v-model="editState[u.id].role" class="inp w-36"><option value="user">一般使用者</option><option value="admin">管理員</option></select>
                                <select v-model="editState[u.id].status" class="inp w-28"><option value="active">啟用</option><option value="disabled">停用</option></select>
                                <label class="flex items-center gap-1.5"><input type="checkbox" v-model="editState[u.id].caps.all_devices" /> 所有裝置</label>
                                <label class="flex items-center gap-1.5"><input type="checkbox" v-model="editState[u.id].caps.all_skills" /> 所有 skills</label>
                                <label class="flex items-center gap-1.5"><input type="checkbox" v-model="editState[u.id].caps.memory" /> 記憶</label>
                                <label class="flex items-center gap-1.5" title="可操作 PAI 伺服器本身（跑指令/開程式）"><input type="checkbox" v-model="editState[u.id].caps.local" /> 主節點</label>
                                <button @click="saveProfile(u)" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-500">儲存</button>
                            </div>
                        </div>

                        <!-- 通知頻道（這個帳號的任務通知送到哪） -->
                        <div>
                            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">通知頻道（此帳號的通知送到這裡）</div>
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <input v-model="editState[u.id].notify.tg_chat_id" placeholder="Telegram chat id" class="inp w-44" />
                                <input v-model="editState[u.id].notify.line_to" placeholder="LINE userId/groupId" class="inp w-56" />
                                <button @click="saveProfile(u)" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-500">儲存頻道</button>
                            </div>
                            <p class="mt-1 text-[11px] text-slate-500">留空＝用全域預設頻道。Bot token 仍是平台共用，這裡只設「送給誰」。</p>
                        </div>

                        <!-- 裝置授權 -->
                        <div v-if="!editState[u.id].caps.all_devices && editState[u.id].role !== 'admin'">
                            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">可存取的裝置</div>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="d in devices" :key="d.id" class="flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-2.5 py-1 text-sm">
                                    <input type="checkbox" :checked="editState[u.id].device_ids.includes(d.id)" @change="toggleIn(editState[u.id].device_ids, d.id)" /> {{ d.name }}
                                </label>
                                <span v-if="!devices.length" class="text-xs text-slate-500">尚無已接入的裝置</span>
                            </div>
                            <button @click="saveDevices(u)" class="mt-2 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-500">儲存裝置授權</button>
                        </div>

                        <!-- skill 授權 -->
                        <div v-if="!editState[u.id].caps.all_skills && editState[u.id].role !== 'admin'">
                            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">可用的 skills</div>
                            <div class="grid max-h-56 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
                                <label v-for="s in skills" :key="s.name" class="flex items-start gap-1.5 rounded px-1.5 py-1 text-sm hover:bg-white/5">
                                    <input type="checkbox" class="mt-1" :checked="editState[u.id].skills.includes(s.name)" @change="toggleIn(editState[u.id].skills, s.name)" />
                                    <span><span class="font-mono text-indigo-300">{{ s.name }}</span><span class="block text-xs text-slate-500">{{ s.description }}</span></span>
                                </label>
                            </div>
                            <button @click="saveSkills(u)" class="mt-2 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-500">儲存 skill 授權</button>
                        </div>

                        <!-- 配對裝置 -->
                        <div class="border-t border-white/10 pt-3">
                            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">配對裝置（手機掃 QR → 綁到此帳號）</div>
                            <button @click="makePair(u)" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm text-white hover:bg-emerald-500">產生配對 QR</button>
                            <div v-if="pairData[u.id]?.qr" class="mt-3 flex items-center gap-4">
                                <img :src="pairData[u.id].qr" class="h-40 w-40 rounded-lg bg-white p-1" alt="配對 QR" />
                                <div class="text-xs text-slate-400">
                                    <p>用手機 App「節點」分頁 → 掃描配對。</p>
                                    <p class="mt-1 text-amber-300">10 分鐘內有效、僅能用一次。</p>
                                    <textarea readonly class="mt-2 h-16 w-64 rounded bg-slate-900 p-2 text-[10px] text-sky-300" @click="$event.target.select()">{{ pairData[u.id].code }}</textarea>
                                </div>
                            </div>
                            <p v-else-if="pairData[u.id]?.error" class="mt-1 text-xs text-red-400">{{ pairData[u.id].error }}</p>
                        </div>

                        <!-- 密碼 / 刪除 -->
                        <div class="flex flex-wrap items-center gap-3 border-t border-white/10 pt-3 text-sm">
                            <input v-model="editState[u.id].password" type="text" placeholder="重設密碼(≥6)" class="inp w-44" />
                            <button @click="resetPw(u)" class="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-slate-300 hover:text-white">重設密碼</button>
                            <button @click="removeUser(u)" class="ml-auto rounded-lg border border-red-400/30 bg-red-500/10 px-3 py-1.5 text-red-300 hover:bg-red-500/20">刪除帳號</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
