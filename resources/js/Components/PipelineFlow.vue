<script setup>
import { computed } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    // [{ key, label, sub, count, accent, icon, active }] — icon 為 UiIcon 名稱
    stages: { type: Array, required: true },
    // 0..1 — 控制光點密度/速度（事件越多越快）
    intensity: { type: Number, default: 0.3 },
});

// 計算連線動畫速度（數值越大越快，週期越短）
const animationDuration = computed(() => {
    const duration = 3 - (props.intensity * 2.2);
    return Math.max(0.8, duration).toFixed(2);
});
</script>

<template>
    <div class="flow">
        <div class="flow-track">
            <template v-for="(stage, i) in stages" :key="stage.key">
                <!-- 節點 -->
                <div class="node" :class="{ 'node--active': stage.active }" :style="{ '--accent': stage.accent }">
                    <div class="node-core">
                        <span class="node-idx">0{{ i + 1 }}</span>
                        <div class="node-icon"><UiIcon :name="stage.icon" :size="20" /></div>
                        <div class="node-count">{{ stage.count }}</div>
                    </div>
                    <div class="node-label">{{ stage.label }}</div>
                    <div class="node-sub">{{ stage.sub }}</div>
                </div>

                <!-- 連接器（最後一節點後不畫） -->
                <div v-if="i < stages.length - 1" class="link">
                    <svg class="h-full w-full" preserveAspectRatio="none" viewBox="0 0 100 64">
                        <defs>
                            <linearGradient :id="`grad-${i}`" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" :stop-color="stage.accent" stop-opacity="0.35" />
                                <stop offset="100%" :stop-color="stages[i + 1].accent" stop-opacity="0.9" />
                            </linearGradient>
                        </defs>

                        <!-- 軌道：細虛線，像電路走線 -->
                        <line x1="0" y1="32" x2="100" y2="32" stroke="rgba(255,255,255,0.08)" stroke-width="1" stroke-dasharray="2 4" />
                        <!-- 端點焊點 -->
                        <rect x="0" y="30" width="3" height="4" :fill="stage.accent" opacity="0.5" />
                        <rect x="97" y="30" width="3" height="4" :fill="stages[i + 1].accent" opacity="0.5" />

                        <!-- 動態資料脈衝 -->
                        <line
                            x1="0" y1="32" x2="100" y2="32"
                            :stroke="`url(#grad-${i})`"
                            stroke-width="1.5"
                            stroke-linecap="square"
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
    padding: 0.75rem 0 0.25rem;
    scrollbar-width: none;
}
.flow::-webkit-scrollbar {
    display: none;
}
.flow-track {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    min-width: 800px;
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

/* 方形核心：hairline + 四角刻度 */
.node-core {
    position: relative;
    z-index: 10;
    width: 66px;
    height: 66px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.02), transparent 40%), rgba(4, 8, 12, 0.9);
    border: 1px solid var(--ops-line, #1c2733);
    transition: border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;
}
/* 四角刻度括號 */
.node-core::before,
.node-core::after {
    content: '';
    position: absolute;
    width: 8px;
    height: 8px;
    pointer-events: none;
    transition: border-color 0.3s ease;
}
.node-core::before {
    top: -1px;
    left: -1px;
    border-top: 1px solid color-mix(in srgb, var(--accent) 40%, transparent);
    border-left: 1px solid color-mix(in srgb, var(--accent) 40%, transparent);
}
.node-core::after {
    bottom: -1px;
    right: -1px;
    border-bottom: 1px solid color-mix(in srgb, var(--accent) 40%, transparent);
    border-right: 1px solid color-mix(in srgb, var(--accent) 40%, transparent);
}

.node--active .node-core {
    border-color: color-mix(in srgb, var(--accent) 55%, transparent);
    box-shadow:
        inset 0 0 18px color-mix(in srgb, var(--accent) 8%, transparent),
        0 0 22px -8px color-mix(in srgb, var(--accent) 70%, transparent);
}
.node--active .node-core::before,
.node--active .node-core::after {
    border-color: var(--accent);
}
.node:hover .node-core {
    transform: translateY(-2px);
    border-color: color-mix(in srgb, var(--accent) 70%, transparent);
}

/* 左上序號 */
.node-idx {
    position: absolute;
    top: 2px;
    left: 4px;
    font-family: var(--font-mono, monospace);
    font-size: 0.5rem;
    letter-spacing: 0.08em;
    color: color-mix(in srgb, var(--accent) 55%, transparent);
}

.node-icon {
    color: color-mix(in srgb, var(--accent) 75%, #8b98a5);
    transition: color 0.3s ease, filter 0.3s ease;
}
.node--active .node-icon {
    color: var(--accent);
    filter: drop-shadow(0 0 5px color-mix(in srgb, var(--accent) 60%, transparent));
}

.node-count {
    font-family: var(--font-mono, monospace);
    font-size: 0.78rem;
    font-weight: 700;
    color: #e6edf3;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.04em;
    line-height: 1;
}
.node--active .node-count {
    color: #fff;
    text-shadow: 0 0 8px color-mix(in srgb, var(--accent) 70%, transparent);
}

/* 文字標籤 */
.node-label {
    margin-top: 0.7rem;
    font-size: 0.74rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    color: #c8d2dc;
    transition: color 0.3s ease;
}
.node--active .node-label {
    color: #fff;
}
.node-sub {
    margin-top: 0.15rem;
    font-family: var(--font-mono, monospace);
    font-size: 0.56rem;
    letter-spacing: 0.16em;
    color: #56626e;
}
.node:hover .node-sub {
    color: #8b98a5;
}

/* ---------- 連接器 (SVG) ---------- */
.link {
    position: relative;
    flex: 1 1 auto;
    height: 64px;
    min-width: 64px;
    margin-top: 2px;
}

/* 資料脈衝動畫 */
.data-stream {
    stroke-dasharray: 16 84;
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
</style>
