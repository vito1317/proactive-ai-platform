<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="登入" />

    <div class="login">
        <div class="bg-glow"></div>
        <div class="relative z-10 w-full max-w-sm">
            <div class="mb-6 text-center">
                <div class="ooda mx-auto mb-3">OODA</div>
                <h1 class="text-xl font-bold text-white">PAI 主動式 AI 中控台</h1>
                <p class="text-sm text-slate-400">請登入以繼續</p>
            </div>

            <form class="glass space-y-4 p-6" @submit.prevent="submit">
                <div>
                    <label class="lbl">Email</label>
                    <input v-model="form.email" type="email" class="inp" autofocus />
                    <p v-if="form.errors.email" class="mt-1 text-xs text-red-400">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="lbl">密碼</label>
                    <input v-model="form.password" type="password" class="inp" />
                </div>
                <label class="flex items-center gap-2 text-xs text-slate-400">
                    <input v-model="form.remember" type="checkbox" class="rounded border-white/20 bg-slate-900" /> 記住我
                </label>
                <button type="submit" :disabled="form.processing" class="btn-primary w-full">
                    {{ form.processing ? '登入中…' : '登入' }}
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.login { position: relative; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #020617; padding: 1rem; }
.bg-glow { position: absolute; inset: 0; pointer-events: none;
    background: radial-gradient(600px circle at 30% 20%, rgba(99,102,241,0.2), transparent 45%), radial-gradient(600px circle at 70% 80%, rgba(34,211,238,0.12), transparent 45%); }
.glass { border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); background: rgba(15,23,42,0.6); backdrop-filter: blur(12px); }
.lbl { display:block; font-size:0.7rem; color:#94a3b8; margin-bottom:0.25rem; }
.inp { width:100%; border-radius:0.5rem; border:1px solid rgba(255,255,255,0.1); background:rgba(2,6,23,0.6); color:#e2e8f0; padding:0.5rem 0.65rem; font-size:0.85rem; }
.inp:focus { outline:none; border-color:#6366f1; }
.btn-primary { border-radius:0.5rem; background:linear-gradient(135deg,#6366f1,#4f46e5); color:#fff; padding:0.55rem 1rem; font-weight:600; font-size:0.9rem; }
.btn-primary:disabled { opacity:0.5; }
.ooda { width:52px; height:52px; border-radius:9999px; display:flex; align-items:center; justify-content:center; font-size:0.6rem; font-weight:700; color:#c7d2fe; background:rgba(2,6,23,0.8); border:1px solid rgba(99,102,241,0.4); }
</style>
