<script setup>
import { ref, onUnmounted } from 'vue';

const prompt = ref('');
const answer = ref('');
const busy = ref(false);
const previewUrl = ref('');
const sessionId = 'web-vision-' + Math.random().toString(36).slice(2);

// 螢幕分享
const sharing = ref(false);
let stream = null;
let liveTimer = null;
const live = ref(false);
const videoEl = ref(null);

function speak(text) {
    try {
        const u = new SpeechSynthesisUtterance(text);
        u.lang = 'zh-TW';
        speechSynthesis.cancel();
        speechSynthesis.speak(u);
    } catch (e) {}
}

async function ask(dataUrl, isLive = false) {
    busy.value = true;
    try {
        const res = await fetch('/api/vision', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ image: dataUrl, prompt: prompt.value, session: sessionId, live: isLive }),
        });
        const j = await res.json();
        answer.value = j.reply || j.error || '（無回應）';
        if (!isLive && j.reply) speak(j.reply);
    } catch (e) {
        answer.value = '請求失敗：' + e.message;
    } finally {
        busy.value = false;
    }
}

// 照片：相機或相簿
function onFile(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
        previewUrl.value = reader.result;
        ask(reader.result);
    };
    reader.readAsDataURL(file);
}

// 螢幕分享
async function startShare() {
    try {
        stream = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 5 } });
        sharing.value = true;
        if (videoEl.value) {
            videoEl.value.srcObject = stream;
            await videoEl.value.play();
        }
        stream.getVideoTracks()[0].addEventListener('ended', stopShare);
    } catch (e) {
        answer.value = '無法分享畫面：' + e.message;
    }
}
function grabFrame() {
    if (!videoEl.value) return '';
    const v = videoEl.value;
    const c = document.createElement('canvas');
    const scale = Math.min(1, 1280 / (v.videoWidth || 1280));
    c.width = (v.videoWidth || 1280) * scale;
    c.height = (v.videoHeight || 720) * scale;
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    return c.toDataURL('image/jpeg', 0.7);
}
function askScreen() {
    const f = grabFrame();
    if (f) { previewUrl.value = f; ask(f); }
}
function toggleLive() {
    live.value = !live.value;
    if (live.value) {
        liveTimer = setInterval(() => { if (!busy.value) { const f = grabFrame(); if (f) ask(f, true); } }, 4000);
    } else if (liveTimer) {
        clearInterval(liveTimer); liveTimer = null;
    }
}
function stopShare() {
    sharing.value = false; live.value = false;
    if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
}
onUnmounted(stopShare);
</script>

<template>
    <div class="min-h-screen bg-slate-950 p-5 text-slate-100">
        <h1 class="mb-1 text-xl font-bold text-cyan-300">👁 看圖 / 看畫面</h1>
        <p class="mb-4 text-xs text-slate-400">拍照或上傳圖片、或分享你的螢幕，讓 AI 看圖回答。</p>

        <input v-model="prompt" placeholder="想問什麼？（留空＝描述畫面）"
            class="mb-3 w-full rounded-lg border border-white/10 bg-black/40 px-3 py-2 text-sm" />

        <div class="mb-4 flex flex-wrap gap-2">
            <label class="cursor-pointer rounded-lg bg-cyan-500/20 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/30">
                📷 拍照 / 上傳
                <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFile" />
            </label>
            <button v-if="!sharing" class="rounded-lg bg-violet-500/20 px-4 py-2 text-sm text-violet-200 hover:bg-violet-500/30" @click="startShare">🖥 分享畫面給 AI</button>
            <template v-else>
                <button class="rounded-lg bg-cyan-500/20 px-4 py-2 text-sm text-cyan-200" @click="askScreen">📸 問這個畫面</button>
                <button class="rounded-lg px-4 py-2 text-sm" :class="live ? 'bg-emerald-500/30 text-emerald-200' : 'bg-white/5 text-slate-300'" @click="toggleLive">{{ live ? '⏸ 停止即時' : '▶ 即時看畫面' }}</button>
                <button class="rounded-lg bg-red-500/20 px-4 py-2 text-sm text-red-200" @click="stopShare">停止分享</button>
            </template>
        </div>

        <video ref="videoEl" v-show="sharing" muted playsinline class="mb-3 max-h-48 rounded-lg border border-white/10" />
        <img v-if="previewUrl && !sharing" :src="previewUrl" class="mb-3 max-h-48 rounded-lg border border-white/10" />

        <div v-if="busy" class="text-sm text-cyan-300">🧠 看圖中…</div>
        <div v-if="answer" class="mt-2 whitespace-pre-wrap rounded-lg border border-white/10 bg-black/30 p-3 text-sm leading-relaxed">{{ answer }}</div>
    </div>
</template>
