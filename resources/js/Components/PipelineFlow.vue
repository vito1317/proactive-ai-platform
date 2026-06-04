<script setup>
import { computed } from 'vue';

const props = defineProps({
    // [{ key, label, sub, count, accent, icon, active }]
    stages: { type: Array, required: true },
    // 0..1 — 控制光點密度/速度（事件越多越快）
    intensity: { type: Number, default: 0.3 },
});

// 計算連線動畫速度（數值越大越快，週期越短）
const animationDuration = computed(() => {
    // 預設速度（最慢 3s，最快 0.8s）
    const duration = 3 - (props.intensity * 2.2);
    return Math.max(0.8, duration).toFixed(2);
});
</script>

<template>
    <div class="flow">
        <div class="flow-track">
            <template v-for="(stage, i) in stages" :key="stage.key">
                <!-- 節點 -->
                <div class="node group" :class="{ 'node--active': stage.active }" :style="{ '--accent': stage.accent }">
                    <!-- 背景環境發光 (Bloom) -->
                    <div class="node-bloom"></div>

                    <div class="node-core">
                        <!-- 旋轉科技環 -->
                        <div class="cyber-ring cyber-ring-outer"></div>
                        <div class="cyber-ring cyber-ring-inner"></div>

                        <!-- 內部內容 -->
                        <div class="node-content">
                            <div class="node-icon">{{ stage.icon }}</div>
                            <div class="node-count">{{ stage.count }}</div>
                        </div>
                    </div>

                    <div class="node-label">{{ stage.label }}</div>
                    <div class="node-sub">{{ stage.sub }}</div>
                </div>

                <!-- 連接器（最後一節點後不畫） -->
                <div v-if="i < stages.length - 1" class="link" :style="{ '--from': stage.accent, '--to': stages[i + 1].accent }">
                    <svg class="w-full h-full" preserveAspectRatio="none" viewBox="0 0 100 64">
                        <defs>
                            <!-- 發光濾鏡 -->
                            <filter :id="`glow-${i}`" x="-20%" y="-20%" width="140%" height="140%">
                                <feGaussianBlur stdDeviation="3" result="blur" />
                                <feComposite in="SourceGraphic" in2="blur" operator="over" />
                            </filter>
                            <!-- 漸層定義 -->
                            <linearGradient :id="`grad-${i}`" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" :stop-color="stage.accent" stop-opacity="0.3" />
                                <stop offset="100%" :stop-color="stages[i+1].accent" stop-opacity="0.8" />
                            </linearGradient>
                        </defs>

                        <!-- 底線 (軌道) -->
                        <line x1="0" y1="32" x2="100" y2="32" stroke="rgba(255,255,255,0.05)" stroke-width="2" />
                        
                        <!-- 動態資料流 (發光) -->
                        <line 
                            x1="0" y1="32" x2="100" y2="32" 
                            :stroke="`url(#grad-${i})`" 
                            stroke-width="2.5"
                            :filter="`url(#glow-${i})`"
                            stroke-linecap="round"
                            class="data-stream"
                            :style="{ animationDuration: `${animationDuration}s` }"
                        />
                    </svg>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.flow {
    overflow-x: auto;
    padding: 1.5rem 0 0.5rem;
    scrollbar-width: none; /* Firefox */
}
.flow::-webkit-scrollbar {
    display: none; /* Chrome, Safari */
}
.flow-track {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    min-width: 800px; /* 增加一點寬度讓連線有空間 */
    gap: 0;
}

/* ---------- 節點 ---------- */
.node {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 110px;
    flex: 0 0 auto;
    text-align: center;
    cursor: default;
}

/* 核心容器 */
.node-core {
    position: relative;
    z-index: 10;
    width: 68px;
    height: 68px;
    border-radius: 9999px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: radial-gradient(circle at center, rgba(15, 23, 42, 0.9) 30%, rgba(2, 6, 23, 1) 100%);
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: inset 0 0 15px rgba(0, 0, 0, 0.8);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

/* 懸浮效果 */
.node:hover .node-core {
    transform: scale(1.08);
    border-color: color-mix(in srgb, var(--accent) 70%, transparent);
    box-shadow: inset 0 0 20px color-mix(in srgb, var(--accent) 20%, transparent),
                0 0 30px -5px color-mix(in srgb, var(--accent) 60%, transparent);
}

/* 環境發光 (Bloom) */
.node-bloom {
    position: absolute;
    top: 5px;
    left: 50%;
    transform: translateX(-50%);
    width: 58px;
    height: 58px;
    border-radius: 50%;
    background: var(--accent);
    filter: blur(25px);
    opacity: 0.1;
    z-index: 1;
    transition: opacity 0.5s ease, filter 0.5s ease;
}
.node--active .node-bloom {
    opacity: 0.45;
    filter: blur(20px);
}
.node:hover .node-bloom {
    opacity: 0.6;
    filter: blur(30px);
}

/* 科幻雙層旋轉環 */
.cyber-ring {
    position: absolute;
    top: 50%;
    left: 50%;
    border-radius: 50%;
    pointer-events: none;
    opacity: 0.3;
    transition: opacity 0.4s;
}

.cyber-ring-outer {
    width: 82px;
    height: 82px;
    margin-top: -41px;
    margin-left: -41px;
    border: 1px dashed var(--accent);
    border-top-color: transparent;
    border-bottom-color: transparent;
    animation: spin-slow 12s linear infinite;
}

.cyber-ring-inner {
    width: 74px;
    height: 74px;
    margin-top: -37px;
    margin-left: -37px;
    border: 1px dotted var(--accent);
    border-left-color: transparent;
    animation: spin-reverse 8s linear infinite;
}

.node--active .cyber-ring {
    opacity: 0.8;
}
.node--active .cyber-ring-outer {
    border-style: solid;
    border-width: 2px;
    border-top-color: transparent;
    border-bottom-color: transparent;
    box-shadow: 0 0 10px inset var(--accent), 0 0 5px var(--accent);
    animation: spin 3s linear infinite;
}
.node--active .cyber-ring-inner {
    border-width: 1.5px;
    border-style: dashed;
    border-left-color: transparent;
    border-right-color: transparent;
    box-shadow: 0 0 8px inset var(--accent);
    animation: spin-reverse 2s linear infinite;
}

/* 內部內容 */
.node-content {
    position: relative;
    z-index: 20;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.node-icon {
    font-size: 1.4rem;
    line-height: 1;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));
    transition: transform 0.3s ease;
}
.node:hover .node-icon {
    transform: translateY(-2px);
}

.node-count {
    margin-top: 3px;
    font-size: 0.85rem;
    font-weight: 800;
    color: #fff;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 4px rgba(0,0,0,1);
    transition: text-shadow 0.3s ease, color 0.3s ease;
}
.node--active .node-count {
    color: #fff;
    text-shadow: 0 0 8px var(--accent), 0 0 12px var(--accent);
}

/* 文字標籤 */
.node-label {
    margin-top: 1rem;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: #e2e8f0;
    text-transform: uppercase;
    transition: color 0.3s ease, text-shadow 0.3s ease;
}
.node--active .node-label {
    color: #fff;
    text-shadow: 0 0 10px color-mix(in srgb, var(--accent) 50%, transparent);
}
.node-sub {
    margin-top: 0.15rem;
    font-size: 0.65rem;
    color: #64748b;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    letter-spacing: 0.5px;
}
.node:hover .node-sub {
    color: #94a3b8;
}

/* ---------- 連接器 (SVG) ---------- */
.link {
    position: relative;
    flex: 1 1 auto;
    height: 64px;
    min-width: 64px;
    margin-top: 2px;
}

/* 資料流動畫 */
.data-stream {
    /* 使用虛線模擬一條條流動的光束 */
    stroke-dasharray: 20 80; /* 線長 20，間距 80 */
    stroke-dashoffset: 100;
    animation-name: stream-flow;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
    animation-duration: 3s; /* 預設值，會被 inline style 覆蓋 */
}

@keyframes stream-flow {
    0% { stroke-dashoffset: 100; }
    100% { stroke-dashoffset: 0; }
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
@keyframes spin-slow {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
@keyframes spin-reverse {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(-360deg); }
}

</style>
