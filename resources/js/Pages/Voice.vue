<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { useVoiceChat } from '@/composables/useVoiceChat';

const voice = useVoiceChat();
const transcript = ref('');
const agentSteps = ref([]);

// 將 useVoiceChat 的狀態映射為能量球的四種視覺狀態
const visualStatus = computed(() => {
    if (!voice.active.value || !voice.connected.value) return 'idle';
    if (voice.speaking.value) return 'speaking';
    // 若 AI 剛回覆步驟，但還沒講話，顯示 thinking
    if (agentSteps.value.length > 0 && agentSteps.value[agentSteps.value.length - 1].includes('>>')) return 'thinking';
    return 'listening'; // 預設連線成功就是聆聽中
});

function toggleConnection() {
    if (voice.active.value) {
        voice.stop();
        transcript.value = '';
        agentSteps.value = [];
    } else {
        transcript.value = '正在連線至核心神經網絡...';
        agentSteps.value = [];
        
        voice.start(
            { mode: 'agent' }, // agent：每輪都走 PAI 真實 agentic（真資料、不亂編、繁中），不傳 url/path 用預設 /voice-rt/socket.io
            {
                onAiText: (t) => { transcript.value = t; },
                onTranscript: (t) => { 
                    transcript.value = t; 
                    agentSteps.value = []; // 新對話開始，清空舊步驟
                },
                onStep: (s) => {
                    const txt = typeof s === 'string' ? s : (s.thought || s.action || '處理中...');
                    agentSteps.value.push('>> ' + txt);
                    if (agentSteps.value.length > 4) agentSteps.value.shift();
                },
                onError: (err) => { transcript.value = '連線異常: ' + err; }
            }
        );
    }
}

// 監聽狀態改變以更新字幕提示
watch(() => voice.status.value, (newStatus) => {
    if (!voice.active.value) {
        transcript.value = '';
    } else if (newStatus && !voice.speaking.value) {
        // 如果還沒有收到真實字幕，顯示系統狀態
        if (!transcript.value || transcript.value.includes('連線') || transcript.value.includes('喚醒')) {
            transcript.value = newStatus;
        }
    }
});

onUnmounted(() => {
    voice.stop();
});
</script>

<template>
    <Head title="全雙工語音連線" />

    <div class="voice-root">
        <div class="bg-glow" :class="visualStatus"></div>
        
        <!-- 頂部狀態列 -->
        <header class="absolute top-0 left-0 right-0 p-6 flex justify-between items-start z-50 pointer-events-none">
            <Link href="/" class="pointer-events-auto flex items-center gap-2 text-slate-400 hover:text-white transition-colors">
                <span class="text-xl">←</span> 
                <span class="font-mono text-xs uppercase tracking-widest">Abort_Link</span>
            </Link>
            
            <div class="text-right font-mono">
                <div class="flex items-center justify-end gap-2 text-xs font-bold tracking-widest" :class="{
                    'text-slate-500': visualStatus === 'idle',
                    'text-amber-400': visualStatus === 'connecting',
                    'text-sky-400': visualStatus === 'listening',
                    'text-purple-400': visualStatus === 'thinking',
                    'text-emerald-400': visualStatus === 'speaking'
                }">
                    <span class="h-2 w-2 rounded-full animate-pulse" :class="{
                        'bg-slate-500': visualStatus === 'idle',
                        'bg-amber-400 shadow-[0_0_8px_#fbbf24]': visualStatus === 'connecting',
                        'bg-sky-400 shadow-[0_0_8px_#38bdf8]': visualStatus === 'listening',
                        'bg-purple-400 shadow-[0_0_8px_#c084fc]': visualStatus === 'thinking',
                        'bg-emerald-400 shadow-[0_0_8px_#34d399]': visualStatus === 'speaking'
                    }"></span>
                    NEURAL_VOICE_LINK // {{ visualStatus.toUpperCase() }}
                </div>
                <div class="mt-1 text-[10px] text-slate-500">Latency: {{ visualStatus === 'idle' ? '--' : '24ms' }}</div>
            </div>
        </header>

        <!-- 中央視覺核心 -->
        <main class="flex-1 flex flex-col items-center justify-center relative z-10">
            <!-- 能量球 -->
            <div class="orb-container">
                <div class="energy-orb" :class="visualStatus">
                    <div class="orb-core" :style="{ 
                        transform: visualStatus === 'listening' ? `scale(${1 + voice.volume.value * 2})` : '',
                        boxShadow: visualStatus === 'listening' ? `0 0 ${40 + voice.volume.value * 200}px rgba(56,189,248,${0.5 + voice.volume.value}), inset 0 0 20px rgba(0,0,0,0.5)` : ''
                    }"></div>
                    <div class="orb-ring inner"></div>
                    <div class="orb-ring outer"></div>
                    <div class="orb-particles" v-if="visualStatus === 'speaking'"></div>
                </div>
            </div>

            <!-- 即時字幕與步驟 -->
            <div class="mt-12 flex flex-col items-center justify-start min-h-[140px] max-w-2xl text-center px-6">
                <!-- AI 步驟 (Trace) -->
                <div v-if="agentSteps.length > 0" class="mb-4 w-full flex flex-col items-center gap-1.5 font-mono text-[11px] text-sky-400">
                    <div v-for="(step, idx) in agentSteps" :key="idx" class="flex items-center gap-2">
                        <span class="animate-pulse text-emerald-400">>></span>
                        <span class="opacity-80">{{ step.thought || step }}</span>
                    </div>
                </div>
                
                <transition name="fade" mode="out-in">
                    <p :key="transcript" class="text-lg md:text-xl font-light tracking-wide text-slate-200" v-if="transcript">
                        {{ transcript }}
                    </p>
                </transition>
            </div>
        </main>

        <!-- 底部控制列 -->
        <footer class="absolute bottom-10 left-0 right-0 flex justify-center z-50">
            <div class="glass px-6 py-4 rounded-full flex items-center gap-6">
                <button @click="voice.toggleMute()" class="w-12 h-12 rounded-full flex items-center justify-center transition-colors border border-white/10"
                        :class="voice.isMuted.value ? 'bg-red-500/20 text-red-400 hover:bg-red-500/30' : 'bg-white/5 hover:bg-white/10 text-slate-300'">
                    <svg v-if="!voice.isMuted.value" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path></svg>
                </button>
                
                <button @click="toggleConnection" class="w-16 h-16 rounded-full flex items-center justify-center shadow-lg transition-all transform hover:scale-105" :class="!voice.active.value ? 'bg-indigo-600 hover:bg-indigo-500 shadow-indigo-500/30' : 'bg-red-500 hover:bg-red-400 shadow-red-500/30'">
                    <svg v-if="!voice.active.value" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    <svg v-else class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>

                <button @click="router.visit('/settings')" class="w-12 h-12 rounded-full flex items-center justify-center bg-white/5 hover:bg-white/10 text-slate-300 transition-colors border border-white/10">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                </button>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.voice-root {
    position: relative;
    height: 100vh;
    width: 100vw;
    background-color: #020617;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* 全域網格背景 - 由 app.css 負責，這裡加上狀態泛光 */
.bg-glow {
    position: absolute;
    inset: 0;
    pointer-events: none;
    transition: all 1s ease;
    opacity: 0.5;
}
.bg-glow.idle { background: radial-gradient(circle at center, rgba(30,41,59,0.5) 0%, transparent 60%); }
.bg-glow.listening { background: radial-gradient(circle at center, rgba(56,189,248,0.15) 0%, transparent 70%); }
.bg-glow.thinking { background: radial-gradient(circle at center, rgba(168,85,247,0.15) 0%, transparent 70%); }
.bg-glow.speaking { background: radial-gradient(circle at center, rgba(16,185,129,0.15) 0%, transparent 70%); }

/* 玻璃質感控制列 */
.glass {
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

/* ---------- 能量球 (Energy Orb) ---------- */
.orb-container {
    position: relative;
    width: 240px;
    height: 240px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.energy-orb {
    position: relative;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    transition: all 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

/* 狀態：閒置 */
.energy-orb.idle .orb-core {
    background: radial-gradient(circle at 30% 30%, #475569, #0f172a);
    box-shadow: 0 0 20px rgba(71,85,105,0.3), inset 0 0 20px rgba(0,0,0,0.8);
    animation: morph-idle 8s ease-in-out infinite;
}

/* 狀態：聆聽 (天空藍) */
.energy-orb.listening .orb-core {
    background: radial-gradient(circle at 30% 30%, #38bdf8, #0369a1);
    box-shadow: 0 0 40px rgba(56,189,248,0.5), inset 0 0 20px rgba(0,0,0,0.5);
    animation: morph-active 3s ease-in-out infinite alternate;
}
.energy-orb.listening .orb-ring.outer {
    border-color: rgba(56,189,248,0.3);
    animation: spin 6s linear infinite;
    opacity: 1;
}

/* 狀態：思考 (紫色) */
.energy-orb.thinking .orb-core {
    background: radial-gradient(circle at 30% 30%, #c084fc, #7e22ce);
    box-shadow: 0 0 50px rgba(192,132,252,0.6), inset 0 0 30px rgba(0,0,0,0.5);
    animation: morph-thinking 2s ease-in-out infinite alternate;
}
.energy-orb.thinking .orb-ring.inner {
    border-color: rgba(192,132,252,0.4);
    animation: spin-reverse 2s linear infinite;
    opacity: 1;
}
.energy-orb.thinking .orb-ring.outer {
    border-color: rgba(192,132,252,0.2);
    animation: spin 3s linear infinite;
    opacity: 1;
}

/* 狀態：說話 (翡翠綠) */
.energy-orb.speaking .orb-core {
    background: radial-gradient(circle at 30% 30%, #34d399, #047857);
    box-shadow: 0 0 60px rgba(52,211,153,0.7), inset 0 0 20px rgba(0,0,0,0.3);
    animation: morph-speaking 0.5s ease-in-out infinite alternate;
}
.energy-orb.speaking .orb-ring {
    opacity: 0;
}
.energy-orb.speaking::after {
    content: '';
    position: absolute;
    inset: -50px;
    border-radius: 50%;
    border: 2px solid rgba(52,211,153,0.5);
    animation: pulse-wave 1.5s cubic-bezier(0.0, 0.0, 0.2, 1) infinite;
}

/* 球體核心元件 */
.orb-core {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    transition: transform 0.1s linear, box-shadow 0.1s linear;
    z-index: 10;
}

/* 環繞掃描線 */
.orb-ring {
    position: absolute;
    border-radius: 50%;
    border: 2px dashed transparent;
    transition: opacity 0.3s;
    opacity: 0;
    pointer-events: none;
}
.orb-ring.inner {
    inset: -15px;
    border-width: 2px;
}
.orb-ring.outer {
    inset: -30px;
    border-width: 1px;
}

/* 動畫 Keyframes */
@keyframes morph-idle {
    0% { border-radius: 50%; transform: scale(1); }
    33% { border-radius: 55% 45% 45% 55% / 55% 45% 55% 45%; }
    66% { border-radius: 45% 55% 55% 45% / 45% 55% 45% 55%; transform: scale(0.98); }
    100% { border-radius: 50%; transform: scale(1); }
}

@keyframes morph-active {
    0% { border-radius: 45% 55% 45% 55% / 55% 45% 55% 45%; }
    100% { border-radius: 55% 45% 55% 45% / 45% 55% 45% 55%; }
}

@keyframes morph-thinking {
    0% { border-radius: 40% 60% 40% 60% / 60% 40% 60% 40%; transform: scale(1) rotate(0deg); }
    100% { border-radius: 60% 40% 60% 40% / 40% 60% 40% 60%; transform: scale(1.05) rotate(10deg); }
}

@keyframes morph-speaking {
    0% { border-radius: 48% 52% 48% 52% / 52% 48% 52% 48%; transform: scale(1.1); }
    100% { border-radius: 52% 48% 52% 48% / 48% 52% 48% 52%; transform: scale(1.25); }
}

@keyframes spin { 100% { transform: rotate(360deg); } }
@keyframes spin-reverse { 100% { transform: rotate(-360deg); } }

@keyframes pulse-wave {
    0% { transform: scale(0.8); opacity: 0.8; border-width: 4px; }
    100% { transform: scale(2.5); opacity: 0; border-width: 0px; }
}

/* 字幕漸變 */
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease, transform 0.3s ease; }
.fade-enter-from { opacity: 0; transform: translateY(10px); }
.fade-leave-to { opacity: 0; transform: translateY(-10px); }
</style>
