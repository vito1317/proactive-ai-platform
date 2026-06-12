# 主動式 AI 平台（Proactive AI Platform）

從「被動式 AI（你下指令它才做事）」轉為**事件驅動 + 持續感知 + 受治理的主動行為**的個人 AI 指揮中心。語音／文字下指令，就能跨節點開關程式、執行指令、查系統狀態、播放音樂、上網查資料、跑背景任務並推播結果（Telegram／LINE／語音念回）。

採用 Laravel 13 + SQLite，本機 llama-server 推理（OpenAI 相容），多租戶、每帳號獨立。

---

## 分層架構

```
L1 感知 Perception   webhook / 日誌掃描 / 排程 → PaiEvent
L2 記憶 Memory       向量 RAG（領域記憶）+ 跨對話使用者長期記憶
L3 認知 Cognition    意圖分類 → 領域協調者 ReAct 迴圈（思考→行動→觀察）
L4 行動 Action       內建技能 + MCP 工具（跨節點 gateway）
L5 治理 Governance   ProactivityPolicy 閘門 + HITL 人類核准 + PAID Protocol 紀錄
```

## 治理層 ProactivityPolicy

主動式 AI 的核心風險是「過度打擾」與「越權行動」，所有協調者提出的動作都過此閘門（`config/pai.php` 的 `governance`，預設寬鬆＝不改變既有行為）：

1. **信心門檻** — 低信心只記錄（observe）
2. **動作風險上限** — 每個動作可設自主等級天花板
3. **回饋自動降級** — 最近常被人類駁回的動作自動變保守
4. **安靜時段** — 非緊急事項不外推（緊急度可突破）
5. **干擾度公式** — `urgency × confidence > interruption_cost` 才允許打擾
6. **每小時打擾上限** — 超過自動抑制外部推播（中控台鈴鐺照常）

自主等級：`OBSERVE(0)` 只記錄／`SUGGEST(1)` 只建議／`ASK(2)` 待人類核准／`ACT(3)` 自動執行。

## PAID Protocol（紀錄格式）

**P**roactive **A**gent **I**nfrastructure with **D**ynamic-finetuning。每次運行輸出一份 6 層 JSON（感知／脈絡／預判／執行／交付／學習）到 `storage/app/pai_records/*.paid.json`，可與 [pai-framework](https://github.com/vito1317/pai-framework)（`pip install paigent`）互通。寫 `paid_protocol_version`，並鏡像舊 `pai_protocol_version` 讓舊讀取器相容。

## paigent 節點生態 + 自我學習

外部節點（Mac／Android／邊緣機）用 `pip install paigent` 跑離線哨兵，事件以 `POST /webhooks/{node}` 推回平台（`WebhookController` 原生認得 paigent 格式）。

**自我學習回饋閉環（PAID 第 1 層）**：節點推 webhook 時自報 `pai_feedback_url`（不寫死、平台零設定）；使用者在中控台／手機核准或駁回後，`GuardianFeedback` 把定論回送該節點的 ReflectiveMemory，下次相似情境檢索教訓注入決策。

## AI 模型設計

- **主模型**：本機 llama-server（`:10003`，預設 Gemma 4 26B-A4B QAT q4_0），可在後台切任一 OpenAI 相容供應商
- **輕量分層**：`llm.small_model` 可設小模型專跑意圖分類／壓縮／記憶萃取（延遲秒級），留空＝用主模型
- **Token 預算制**：依 `llm.context_window` 動態裁切歷史／記憶，避免超出上下文被無聲截斷
- **記憶 RAG**：相似度門檻 + embedding 快取 + 全量掃描（不漏舊記憶）
- **Prompt 注入防護**：MCP 工具描述／日誌／記憶注入前消毒
- **韌性**：LLM 呼叫退避重試、JSON 解析容錯、無聲失敗皆留日誌

## 安裝

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve   # 或經 nginx 反代
```

設定（`config/pai.php` 或後台）：LLM 端點／金鑰、治理參數、通知管道、語音 STT。

## 測試

```bash
php artisan test
```

## 相關專案

- [pai-framework](https://github.com/vito1317/pai-framework) — Python 主動式 agent 框架（PyPI `paigent`）
- [pai-gateway](https://github.com/vito1317/pai-gateway) — 節點 gateway（Mac／Linux）
- [pai-gateway-android](https://github.com/vito1317/pai-gateway-android) — Android gateway
- HF 模型：[gemma-guardian-pai](https://huggingface.co/vito95311/gemma-guardian-pai)、[minicpm-o-guardian-pai](https://huggingface.co/vito95311/minicpm-o-guardian-pai)

作者：Vito（service@vito1317.com）
