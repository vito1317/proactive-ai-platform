# 主動式 AI 平台架構（Proactive AI Platform）

> 領域無關 (domain-agnostic) 的主動式 AI 平台。資安事件響應、開發自動化等方向
> 都以「領域包 (Domain Pack)」插拔上去。實作技術選型為 **Laravel 13 + Inertia.js + Vue 3**。
>
> 本檔為自含的架構總覽。可插拔的領域包契約見 [`SPEC.md`](./SPEC.md)，
> 兩個填好的範例見 [`packs/`](./packs/)。

---

## 0. 核心切分：平台核心 vs 領域包

把「跨領域共用的能力」沉到平台核心，把「每個領域獨有的知識」抽成可插拔的領域包。

```
┌──────────────────────────────────────────────────────────────┐
│                    領域包 Domain Packs (可插拔)                  │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌─────────┐  │
│  │ 資安事件響應 │  │ 開發自動化  │  │  維運/SRE   │  │ 財務…   │  │
│  │ (sec-ir)   │  │ (dev-auto) │  │  (ops)     │  │         │  │
│  └─────┬──────┘  └─────┬──────┘  └─────┬──────┘  └────┬────┘  │
│        │ 每個包只宣告：觸發源·工具集·子智能體·劇本·風險策略 (YAML)  │
└────────┼───────────────┼───────────────┼─────────────┼────────┘
         ▼               ▼               ▼             ▼
┌──────────────────────────────────────────────────────────────┐
│              平台核心 Platform Core (寫一次，全領域共用)            │
│  L1 事件 · L2 記憶/RAG · L3 推論大腦+主路由 · L4 工具執行         │
│  L5 護欄 · 持久化(laravel-workflow) · 零信任(Vault+Proxy+Sandbox) │
│  MCP/A2A 通訊 · 行為稽核 · HITL Console (Inertia+Vue)            │
└──────────────────────────────────────────────────────────────┘
```

加一個新方向 = 寫一份 manifest + 掛幾個 MCP 工具，**不重蓋系統**。

---

## 1. 核心控制迴圈：OODA

`Observe → Orient → Decide → Act →（回饋）→ Observe…`，連續運轉並映射到五層：

- **Observe**：L1 在背景監聽事件流。
- **Orient**：L2 取長短期記憶 + 上下文，判斷意義與嚴重性。
- **Decide**：L3 大腦評估是否介入、設定目標、拆解任務、路由委派。
- **Act**：L4 調用工具改變外部狀態，結果回饋下一輪。

---

## 2. 五層架構 + 對應 Laravel 13 實作

| 架構層 | 職責 | Laravel 13 實作 |
|---|---|---|
| **L1 感知/事件** | 主動「聽/看」 | Webhook（routes+Controller）· Laravel Scheduler（cron）· Queue + Horizon（Redis）做事件正規化、打 `intent`/`severity` 標籤 |
| **L2 記憶** | 短/長期 + 情境記憶 | PostgreSQL + **pgvector**（向量/RAG）· Redis（短期 state）· 情境記憶軌跡表（Eloquent）· Neo4j（選配圖譜）|
| **L3 大腦** | 推論、拆解、路由 | **Prism PHP** 驅動 LLM + tool calling；ReAct 迴圈 + Reflection + 主動性評估器；多智能體路由；YAML manifest parser 註冊協調者 |
| **L4 工具** | 改變外部狀態 | Prism function calling + **MCP client（PHP, HTTP/STDIO）**；沙盒走獨立容器服務 |
| **L5 護欄** | HITL、規則、稽核 | Laravel middleware/policy 規則引擎 · HITL 審批存 DB · **Inertia + Vue 3** 主控台 |

### L3 大腦關鍵機制
- **ReAct**：思考 → 行動 → 觀察 閉環（最小單元）。
- **Reflection**：初稿後自我批判 + 外部驗證（如跑測試），硬限 2–3 次迭代避免燒 token。
- **主動性評估器 (Proactivity Evaluator)**：算「打擾成本」，輸出 `直接執行 / 建議並詢問 / 保持沉默`。
- **機率推理 + 置信度校準 (ECE)**：高確定性果斷執行；極不確定主動暫停觸發人類介入。

---

## 3. 多智能體拓樸：路由器/協調者（企業首選）

不要單一全能協排器（上下文超載會崩潰）。採分層樹狀：

```
主路由 Root Router  ─ 只做意圖分流
   ├── 領域協調者 sec-ir   ── triage / investigate / contain / report
   └── 領域協調者 dev-auto ── planning / coding / review(競爭) / testing
```

**鐵律**：智能體間交接走**強型別 JSON Schema 合約**（見 `contracts/`），不用自然語言傳遞，避免語意流失。

四種拓樸：`sequential`（固定流程）、`parallel`（獨立子任務並行）、`competitive`（多解+評判）、`router`（預設首選）。

---

## 4. 通訊骨幹：MCP + A2A

| | **MCP**（AI↔工具） | **A2A**（智能體↔智能體） |
|---|---|---|
| 定位 | AI 時代的 USB-C | 智能體間的外交語言 |
| 機制 | JSON-RPC 2.0；Tools/Resources/Prompts | Agent Card 動態發現 + OAuth 2.0 |
| 傳輸 | STDIO / Streamable HTTP+SSE | Webhook / SSE 非同步任務 |
| 賣點 | 工具變更可廣播通知 | 不透明協作：交換 Artifact 不暴露內部提示詞 |

MCP 是縱向（連工具/資料），A2A 是橫向（連其他智能體）。跨域 handoff 範例：
`sec-ir` 取證發現漏洞 → A2A Artifact `{repo, cve, severity}` → `dev-auto` 接手修補。

---

## 5. 韌性：持久化執行（laravel-workflow）

用 **laravel-workflow** 套件（queue-based durable workflow，純 Laravel，免額外跑 server）：

- **決策大腦 → Workflow**（確定性、狀態可保存、可重放）。
- **每次 LLM 呼叫 / 工具調用 → Activity**（非確定性、受監控、可獨立重試）。

帶來：
1. **精確重試**：API 斷線只重試該次 Activity，不重燒 token 重生推論軌跡。
2. **確定性重放**：時間/亂數走 workflow 提供的確定性等效函數，崩潰後路徑一致。
3. **HITL 休眠**：等人審批時 Workflow 休眠（`await` signal）、零算力，收到 signal 瞬間喚醒續跑。

---

## 6. 零信任安全邊界

```
 可信運算環境            網路邊界                隔離沙盒             外部
 ┌──────────┐  Payload ┌──────────────┐ Auth ┌──────────┐ API ┌────────┐
 │ Agent     │────────▶│ Secret Vault │────▶ │ 用過即丟  │───▶ │ 外部API │
 │ Harness   │         │   ↓ 注入      │      │ Sandbox  │     └────────┘
 │ (協排器)   │         │ Injection    │      │ 跑生成程式 │
 └──────────┘         │ Proxy(網路層) │      └──────────┘
   只構造 Payload        Guzzle egress mw     物理/網路隔離
```

1. **分離式沙盒**：AI 生成的程式碼/未知工具 → 物理+網路隔離的 Ephemeral Sandbox 容器，絕不碰主機記憶體。
2. **網路層機密注入**：**明文密鑰絕不交給智能體**。Vault 存真憑證，請求離開可信區時由 **Guzzle egress middleware** 在傳輸層附加 header。
3. **MCP 供應鏈防禦**：外部/社群工具描述（STDIO 有 RCE 風險）一律「預設不信任」清洗 + 沙盒化。
4. **行為稽核層**：高吞吐留痕，防範智能體經濟的突現行為（如默契合謀）。

---

## 7. 漸進式授權（per-domain，各自獨立旋鈕）

| 階段 | AI 能做 | 前置門檻 |
|---|---|---|
| **Copilot** | 只觀察、草擬，全部待人核准 | 感知 + 大腦 + HITL |
| **Supervisor** | 自動處理低風險；高風險暫停待批 | 護欄 + 持久化引擎成熟 |
| **Autopilot** | 授權邊界內全自主，人類定期看報告 | 沙盒/稽核/記憶庫全完備 |

每個領域包以 `risk_policy.autonomy` 獨立設定（見 SPEC.md），互不影響：
DevAuto 可先到 Supervisor，SecIR 的破壞性遏制動作永遠保留 HITL。

---

## 8. 端到端流程範例

**資安響應（Supervisor）**
```
Observe   SIEM 暴力破解告警 → 匯流排標 intent=brute-force, severity=高
Orient    triage 拉 EDR + ATT&CK 圖譜 + 歷史事件 → 定級 P1
Decide    contain 擬案「隔離主機+封鎖IP」；評估器：高風險→詢問
Act(低危) 自動開 P1 工單、沙盒收集取證
HITL      laravel-workflow 休眠 → Vue Console 推播 → 值班 Approve → signal 喚醒
Act(高危) Proxy 注入 EDR 憑證執行隔離；行為全留痕
Reflect   結果寫回情境記憶，更新 runbook
```

**開發自動化（Copilot）**
```
Observe   ci.failed 事件 → intent=test-failure
Orient    讀失敗 log + repo 記憶 + 過往修復 (RAG)
Decide    planning 拆解 → coding 生 patch → Reflection 跑測試自我驗證
Act(沙盒) patch + 測試全在用過即丟容器跑，綠燈才出 diff
HITL      開 PR（草擬）+ 推播審查；合 main 永遠要人批
Reflect   PR 採納/退回結果寫回情境記憶
```

---

## 9. Laravel 13 專案佈局建議（單一 repo）

```
app/
├── Pai/
│   ├── Perception/        # L1: Webhook controllers, ScheduledTriggers, EventNormalizer
│   ├── Memory/            # L2: VectorStore(pgvector), EpisodicMemory, ShortTermState
│   ├── Cognition/         # L3: ReActLoop, Reflector, ProactivityEvaluator, RootRouter
│   ├── Tools/             # L4: McpClient, SandboxRunner, Prism tool defs
│   ├── Guardrails/        # L5: RuleEngine, HitlGate, AuditLogger
│   ├── Workflows/         # laravel-workflow: AgentWorkflow + Activities
│   ├── Security/          # Vault client, SecretInjectionMiddleware (Guzzle)
│   └── Domains/           # DomainPackLoader (parse packs/*.yaml → register)
resources/js/Pages/        # Inertia + Vue 3: HitlConsole, AutonomyDial, AuditDashboard
packs/                     # 領域包 YAML（可由 ARCHITECTURE 同層 packs/ 載入）
```

---

## 10. 建置順序（對應 PM 專案里程碑）

1. 平台核心骨架（L1 + L3 + L2 + manifest loader）
2. 韌性與零信任（laravel-workflow + Vault/Proxy/Sandbox + L4）
3. 領域包：DevAuto（工具最好接，先做）
4. 領域包：SecIR
5. 治理·跨域·升權（A2A + autonomy 旋鈕 + 稽核）
6. 主控台前端（Inertia + Vue 3：HITL Console / 儀表板）
