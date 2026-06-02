<script setup>
import { computed } from 'vue';

const props = defineProps({
    // [{ key, label, sub, count, accent, icon, active }]
    stages: { type: Array, required: true },
    // 0..1 — 控制光點密度/速度（事件越多越快）
    intensity: { type: Number, default: 0.3 },
});

// 每段連接器上的光點數量，隨 intensity 增加
const packetsPerLink = computed(() => 2 + Math.round(props.intensity * 4));
const flowDuration = computed(() => `${(3.2 - props.intensity * 1.8).toFixed(2)}s`);
</script>

<template>
    <div class="flow">
        <div class="flow-track">
            <template v-for="(stage, i) in stages" :key="stage.key">
                <!-- 節點 -->
                <div class="node" :class="{ 'node--active': stage.active }" :style="{ '--accent': stage.accent }">
                    <div class="node-ring"></div>
                    <div class="node-core">
                        <div class="node-icon">{{ stage.icon }}</div>
                        <div class="node-count">{{ stage.count }}</div>
                    </div>
                    <div class="node-label">{{ stage.label }}</div>
                    <div class="node-sub">{{ stage.sub }}</div>
                </div>

                <!-- 連接器（最後一節點後不畫） -->
                <div v-if="i < stages.length - 1" class="link" :style="{ '--from': stage.accent, '--to': stages[i + 1].accent }">
                    <div class="link-line"></div>
                    <span
                        v-for="p in packetsPerLink"
                        :key="p"
                        class="packet"
                        :style="{ animationDuration: flowDuration, animationDelay: `${(p / packetsPerLink) * parseFloat(flowDuration)}s` }"
                    ></span>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.flow {
    overflow-x: auto;
    padding: 0.5rem 0 0.25rem;
}
.flow-track {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    min-width: 720px;
    gap: 0;
}

/* ---------- 節點 ---------- */
.node {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 96px;
    flex: 0 0 auto;
    text-align: center;
}
.node-core {
    position: relative;
    z-index: 2;
    width: 64px;
    height: 64px;
    border-radius: 9999px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.85);
    border: 1px solid color-mix(in srgb, var(--accent) 55%, transparent);
    box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.03) inset;
    transition: box-shadow 0.4s ease;
}
.node-ring {
    position: absolute;
    top: -6px;
    left: 50%;
    transform: translateX(-50%);
    width: 76px;
    height: 76px;
    border-radius: 9999px;
    background: conic-gradient(from 0deg, transparent, var(--accent), transparent 60%);
    opacity: 0.0;
    filter: blur(1px);
}
.node--active .node-ring {
    opacity: 0.85;
    animation: spin 3.5s linear infinite;
}
.node--active .node-core {
    box-shadow: 0 0 22px -2px var(--accent), 0 0 0 1px var(--accent) inset;
}
.node-icon { font-size: 1.25rem; line-height: 1; }
.node-count {
    margin-top: 2px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #fff;
    font-variant-numeric: tabular-nums;
}
.node-label {
    margin-top: 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #e2e8f0;
}
.node-sub { font-size: 0.65rem; color: #64748b; }

/* ---------- 連接器 + 光點 ---------- */
.link {
    position: relative;
    flex: 1 1 auto;
    height: 64px;
    min-width: 48px;
    display: flex;
    align-items: center;
}
.link-line {
    position: absolute;
    top: 32px;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg,
        color-mix(in srgb, var(--from) 60%, transparent),
        color-mix(in srgb, var(--to) 60%, transparent));
    opacity: 0.35;
}
.packet {
    position: absolute;
    top: 28px;
    left: 0;
    width: 8px;
    height: 8px;
    border-radius: 9999px;
    background: var(--to);
    box-shadow: 0 0 10px 2px var(--to);
    animation-name: travel;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
}
@keyframes travel {
    0%   { transform: translateX(-8px) scale(0.6); opacity: 0; }
    12%  { opacity: 1; }
    88%  { opacity: 1; }
    100% { transform: translateX(calc(100% + 8px)) scale(1); opacity: 0; }
}
@keyframes spin {
    to { transform: translateX(-50%) rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
    .node--active .node-ring { animation: none; }
    .packet { animation: none; opacity: 0; }
}
</style>
