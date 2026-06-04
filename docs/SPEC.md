# Domain Pack Manifest 規格 (v1)

> 「領域包 (Domain Pack)」是把一個業務方向（資安響應、開發自動化…）插進平台核心的**宣告式契約**。
> 平台核心寫一次；每個領域只交一份 manifest（YAML）+ 掛幾個 MCP 工具，即可上線。
>
> 本規格與框架無關。可驗證的機器版見 [`schema/domain-pack.schema.json`](./schema/domain-pack.schema.json)。
> 填好的範例見 [`packs/sec-ir.yaml`](./packs/sec-ir.yaml)、[`packs/dev-auto.yaml`](./packs/dev-auto.yaml)。

---

## 1. 平台如何載入一個領域包（Laravel 13）

```
Laravel boot
  └─ DomainPackLoader: glob packs/*.yaml
       ├─ 1. 對照 schema/domain-pack.schema.json 驗證（不合法 → 拒載 + log）
       ├─ 2. 建立領域協調者 agent（coordinator），掛到 RootRouter
       ├─ 3. triggers → 訂閱 L1：webhook 路由 + Scheduler cron
       ├─ 4. tools → 綁定 L4 MCP 白名單（標記高風險者進 hitl_required）
       ├─ 5. memory.namespace → 建/掛 pgvector collection + 知識來源
       └─ 6. risk_policy → 套用 L5 護欄（autonomy 階段、HITL 清單、限流）
```

一個領域包**只能**透過此契約影響平台行為——不得繞過去直接改核心。這是「通用性」的保證。

---

## 2. 欄位定義

### 2.1 頂層
| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `domain` | string (kebab-case) | ✓ | 領域唯一鍵，如 `sec-ir`、`dev-auto`。對應記憶 namespace 與路由鍵。 |
| `coordinator` | string | ✓ | 領域協調者 agent 名稱，如 `sec-ir-coordinator`。 |
| `description` | string | ✓ | 一句話描述此領域職責。 |
| `triggers` | object | ✓ | 見 §2.2，對應 **L1**。 |
| `tools` | array | ✓ | 見 §2.3，對應 **L4**。 |
| `agents` | object | ✓ | 見 §2.4，對應 **L3**。 |
| `playbooks` | array<string> | ✗ | 可重用工作流名稱（走 MCP Prompts）。 |
| `memory` | object | ✓ | 見 §2.5，對應 **L2**。 |
| `risk_policy` | object | ✓ | 見 §2.6，對應 **L5**。 |
| `contracts` | object | ✓ | 見 §2.7。 |
| `slo` | object | ✗ | 服務水準目標，如 `{ ack: 60s }`。 |

### 2.2 `triggers`（L1 感知/事件）
```yaml
triggers:
  events: [siem.alert, edr.detection]   # 訂閱的事件主題；平台註冊 webhook 路由
  cron:   ["0 8 * * 2-5: 每日威脅情報彙整"]  # "<cron 運算式>: <說明>"；走 Laravel Scheduler
```
| 子欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `events` | array<string> | ✗* | 事件主題鍵。平台收到後正規化、標 `intent`/`severity` 再喚醒協調者。 |
| `cron` | array<string> | ✗* | `"<cron>: <說明>"`。時間觸發。 |

\* `events` 與 `cron` 至少要有一個非空。

### 2.3 `tools`（L4 工具白名單）
```yaml
tools:
  - { uri: "mcp://siem",     perms: [read] }
  - { uri: "mcp://firewall", perms: [write], risk: high }   # 高風險 → 自動進 hitl_required
```
| 子欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `uri` | string (`mcp://...`) | ✓ | MCP server 位址。**預設不信任**：外部/社群工具描述須清洗 + 沙盒化。 |
| `perms` | array<`read`\|`write`\|`exec`> | ✓ | 允許的操作。 |
| `risk` | `low`\|`medium`\|`high` | ✗ | 預設 `low`。標 `high` 的操作平台會強制納入 HITL。 |

### 2.4 `agents`（L3 多智能體）
```yaml
agents:
  topology: router        # router | sequential | parallel | competitive
  roster:
    - { name: triage,      role: "事件分流定級" }
    - { name: investigate, role: "取證與關聯分析" }
```
| 子欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `topology` | enum | ✓ | 子智能體編排方式（見 ARCHITECTURE §3）。 |
| `roster` | array<{name, role}> | ✓ | 子智能體清單。`name` kebab/snake，`role` 為其 system 職責摘要。 |

### 2.5 `memory`（L2 記憶/知識）
```yaml
memory:
  namespace: sec-ir
  knowledge:
    - { type: vector, source: "past-incidents" }
    - { type: graph,  source: "mitre-attack" }
    - { type: doc,    source: "runbooks/" }
```
| 子欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `namespace` | string | ✓ | 記憶隔離命名空間（= pgvector collection 前綴）。領域間不共享，避免污染。 |
| `knowledge` | array<{type, source}> | ✓ | `type` ∈ `vector`\|`graph`\|`doc`。RAG/圖譜檢索來源。 |

### 2.6 `risk_policy`（L5 護欄 / 漸進式授權）
```yaml
risk_policy:
  autonomy: supervisor                       # copilot | supervisor | autopilot
  hitl_required: [isolate-host, firewall.block]
  rate_limits: { "firewall.block": "5/min" }
```
| 子欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `autonomy` | enum | ✓ | 此領域目前自治階段。各領域獨立。 |
| `hitl_required` | array<string> | ✓ | 必須人類核准的動作鍵（除 `risk:high` 工具外的補充清單）。 |
| `rate_limits` | map<string,string> | ✗ | `"動作鍵": "次數/時窗"` 硬限流。 |

**autonomy 語意**：
- `copilot`：所有 `write`/`exec` 動作都要人核准（只能草擬）。
- `supervisor`：`low`/`medium` 風險自動執行；`high` 風險 + `hitl_required` 暫停待批。
- `autopilot`：授權邊界內全自主；僅 `hitl_required` 仍需人。

### 2.7 `contracts`
```yaml
contracts:
  output: contracts/IncidentReport.schema.json   # 此領域對外/跨域輸出的強型別 schema
```
| 子欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `output` | string (path) | ✓ | 領域產物的 JSON Schema 路徑。智能體間/跨域 A2A 一律照此契約傳遞。 |

---

## 3. 跨域 A2A handoff 格式

領域之間用 A2A 交換 **Artifact**，不暴露彼此內部提示詞/記憶。Artifact 信封：
```json
{
  "from": "sec-ir",
  "to": "dev-auto",
  "task": "patch-vulnerability",
  "artifact": { "repo": "owner/svc", "cve": "CVE-2026-1234", "severity": "high" },
  "reply_to": "a2a://sec-ir/incidents/INC-42"
}
```
- `artifact` 內容須符合**接收方**能消化的契約；雙方在 Agent Card 宣告可接受的 task 類型。
- 回報走 `reply_to`（Webhook/SSE 非同步）。

---

## 4. 新增一個領域（Onboarding 6 步）

1. 複製本規格，填一份 `packs/<domain>.yaml`。
2. 把該領域工具包成 MCP server（白名單 + 沙盒 + 零信任）。
3. 建 `memory.namespace`，灌 runbook/規範/歷史案例。
4. 定義 `contracts/<Output>.schema.json`。
5. 設 `risk_policy`：初始 `autonomy: copilot`，列出 `hitl_required`。
6. 重啟平台（DomainPackLoader 自動驗證 + 註冊），主路由即可分流該領域。

> 核心不動一行。這就是「通用平台」的本體。
