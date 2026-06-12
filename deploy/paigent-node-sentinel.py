#!/usr/bin/env python3
"""paigent 節點哨兵 — 在任何節點（Mac / 邊緣機）跑一個離線哨兵，事件推回平台。

    pip install paigent
    PAI_PLATFORM_URL=https://pai.vito1317.com NODE_NAME=mac \\
        python3 deploy/paigent-node-sentinel.py

行為：
  - ThresholdTrigger 監控 CPU load，超標 → RuleBrain 判斷 → 治理層核
  - 通知/建議透過 WebhookNotifier 推回平台 POST /webhooks/{NODE_NAME}
    （平台 WebhookController 原生認得 paigent payload，自動轉成 PaiEvent
      → 意圖分類 → 領域協調者 → 平台自己的治理閘/HITL）
  - 平台斷線不影響本地哨兵運作（webhook 失敗只記 log）

要換成 LLM 腦（本地 GGUF）：pip install "paigent[local]" 並載入
huggingface.co/vito95311/gemma-guardian-pai 的 .pai（見 README）。
"""
import logging
import os

from pai import (
    AutonomyLevel,
    Intent,
    PAIAgent,
    ProactivityPolicy,
    Rule,
    RuleBrain,
    ThresholdTrigger,
    WebhookNotifier,
)
from pai.memory import Memory

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(message)s")

PLATFORM = os.environ.get("PAI_PLATFORM_URL", "http://127.0.0.1:8083").rstrip("/")
NODE = os.environ.get("NODE_NAME", "node")
CPU_THRESHOLD = float(os.environ.get("CPU_THRESHOLD", "3.0"))  # 1 分鐘 load average


def load_avg() -> float:
    return os.getloadavg()[0]


def main() -> None:
    notify = WebhookNotifier(f"{PLATFORM}/webhooks/{NODE}")

    brain = RuleBrain([
        Rule("cpu-breach", lambda e, ctx: Intent(
            action="__notify__",
            confidence=0.9,
            urgency=0.9,
            rationale=f"節點 {NODE} CPU load {e.payload.get('value'):.2f} 超過 {CPU_THRESHOLD}",
            requested_level=AutonomyLevel.SUGGEST,
        ) if e.kind == "metric.breach" else None),
    ])

    agent = PAIAgent(
        name=f"{NODE}-sentinel",
        brain=brain,
        policy=ProactivityPolicy(max_interruptions_per_hour=6),
        memory=Memory(f"{NODE}-sentinel.db"),
        actions={"__notify__": notify},
    ).add_trigger(ThresholdTrigger("cpu-monitor", metric_fn=load_avg,
                                   threshold=CPU_THRESHOLD, check_interval=30))

    logging.info("paigent 哨兵啟動：%s → %s/webhooks/%s", agent.name, PLATFORM, NODE)
    agent.run()  # Ctrl-C 結束


if __name__ == "__main__":
    main()
