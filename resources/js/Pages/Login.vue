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
        <div class="relative z-10 w-full max-w-sm">
            <div class="mb-6 text-center">
                <div class="sigil mx-auto mb-4"><span class="sigil-block"></span></div>
                <h1 class="font-mono text-lg font-bold tracking-[0.14em] text-white">PAI <span class="text-(--ops-green)">//</span> OPS CONSOLE</h1>
                <p class="mt-1 font-mono text-[11px] tracking-[0.14em] text-(--ops-ink-faint)">AUTH REQUIRED · 請登入以繼續</p>
            </div>

            <form class="glass corners space-y-4 p-6" @submit.prevent="submit">
                <div>
                    <label class="lbl">EMAIL</label>
                    <input v-model="form.email" type="email" class="inp" autocomplete="email" autofocus />
                    <p v-if="form.errors.email" class="mt-1 text-xs text-(--ops-red)">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="lbl">密碼</label>
                    <input v-model="form.password" type="password" class="inp" autocomplete="current-password" />
                </div>
                <label class="flex cursor-pointer items-center gap-2 font-mono text-xs text-(--ops-ink-dim)">
                    <input v-model="form.remember" type="checkbox" class="accent-(--ops-green)" /> 記住我
                </label>
                <button type="submit" :disabled="form.processing" class="btn-primary w-full">
                    {{ form.processing ? 'AUTHENTICATING…' : 'LOGIN · 登入' }}
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.login {
    position: relative;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.lbl {
    display: block;
    font-family: var(--font-mono);
    font-size: 0.62rem;
    letter-spacing: 0.14em;
    color: var(--ops-ink-faint);
    margin-bottom: 0.3rem;
}
.sigil {
    width: 44px;
    height: 44px;
    border: 1px solid var(--ops-green);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.sigil::before,
.sigil::after {
    content: '';
    position: absolute;
    width: 7px;
    height: 7px;
}
.sigil::before { top: -4px; left: -4px; border-top: 1px solid var(--ops-green); border-left: 1px solid var(--ops-green); }
.sigil::after { bottom: -4px; right: -4px; border-bottom: 1px solid var(--ops-green); border-right: 1px solid var(--ops-green); }
.sigil-block {
    width: 14px;
    height: 14px;
    background: var(--ops-green);
    box-shadow: 0 0 12px rgba(63, 220, 151, 0.8);
    animation: sigil-pulse 3s ease-in-out infinite;
}
@keyframes sigil-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.45; }
}
</style>
