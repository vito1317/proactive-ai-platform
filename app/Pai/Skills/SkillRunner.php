<?php

namespace App\Pai\Skills;

use App\Pai\Chat\Conversation;
use App\Pai\Cognition\LlmClient;
use App\Pai\Settings\Settings;
use Throwable;

/**
 * 由自然語言挑選並執行技能。
 *
 * 高風險（自我修改）操作的批准有兩條路：
 *  1) 後台開啟 skills.allow_system_writes（等同 autopilot，直接執行）
 *  2) 對話確認：先把待執行技能暫存在會話，回覆要求確認；使用者回「確認」才執行
 */
class SkillRunner
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly SkillRegistry $registry,
        private readonly Settings $settings,
    ) {}

    /** MCP 工具（mcp__<node>__<tool>）的簡短友善標籤——不要把給 AI 看的整段工具描述顯示給使用者。 */
    private function mcpStepLabel(string $name): ?string
    {
        if (! str_starts_with($name, 'mcp__')) {
            return null;
        }
        $tool = (string) (explode('__', $name)[2] ?? $name);
        $map = [
            'browser_navigate' => '🌐 開啟網頁…', 'browser_read' => '📄 讀取網頁內容…',
            'browser_snapshot' => '🔍 分析網頁元素…', 'browser_click' => '🖱️ 點擊網頁…',
            'browser_type' => '⌨️ 輸入文字…', 'browser_back' => '↩️ 返回上一頁…',
            'browser_reload' => '🔄 重新載入網頁…', 'browser_current_url' => '🌐 取得目前網址…',
            'maps_route' => '🗺️ 開啟地圖路線…', 'open_url' => '🌐 開啟連結…', 'open_app' => '🚀 開啟 App…',
            'show_document' => '📋 在手機顯示文件…', 'device_location' => '📍 取得手機定位…',
            'phone_notify' => '🔔 發送手機通知…', 'phone_speak' => '🔊 手機念出…',
            'set_volume' => '🔊 調整音量…', 'set_brightness' => '☀️ 調整亮度…',
            'flashlight' => '🔦 手電筒…', 'battery_status' => '🔋 查電量…',
            'clipboard_set' => '📋 複製到剪貼簿…', 'clipboard_get' => '📋 讀取剪貼簿…',
            'share_text' => '↗️ 分享…', 'play_music' => '🎵 播放音樂…', 'media_control' => '⏯ 媒體控制…',
            'phone_call' => '📞 撥打電話…', 'notifications_list' => '🔔 讀取通知…', 'notification_reply' => '💬 回覆訊息…',
            'screen_snapshot' => '👁 讀取手機畫面…', 'screen_click' => '👆 點擊畫面…', 'screen_type' => '⌨️ 輸入文字…',
            'screen_swipe' => '↕️ 滑動畫面…', 'screen_back' => '↩️ 返回…', 'screen_home' => '🏠 回主畫面…',
            'screen_shot' => '📸 截圖判讀畫面…',
        ];

        return $map[$tool] ?? ('🔧 '.$tool.'…');
    }

    /** 把技能對應成一句「正在做什麼」的步驟說明（給前端活動軌跡）。 */
    private function stepLabel(Skill $skill): string
    {
        if ($mcp = $this->mcpStepLabel($skill->name())) {
            return $mcp;
        }

        return [
            'web-search' => '🔍 上網搜尋…',
            'execute-code' => '🧬 一次串接多個工具…',
            'wait' => '⏳ 等待中…',
            'loop' => '🔁 重複/輪詢偵測…',
            'answer-from-web' => '🔍 上網搜尋並閱讀資料…',
            'web-fetch' => '🌐 開啟網頁讀取內容…',
            'read-file' => '📄 讀取檔案…',
            'write-file' => '✏️ 寫入檔案…',
            'edit-file' => '✏️ 編輯檔案…',
            'insert-in-file' => '✏️ 修改檔案…',
            'run-shell' => '💻 執行終端機指令…',
            'open-app' => '🚀 啟動程式…',
            'get-settings' => '⚙️ 讀取平台設定…',
            'update-setting' => '⚙️ 調整平台設定…',
            'list-domains' => '🧩 盤點領域包…',
            'describe-domain' => '🧩 查看領域包細節…',
            'toggle-domain' => '🧩 啟用/停用領域…',
            'merge-domains' => '🧩 整合領域包…',
            'restart-workers' => '🔄 重啟背景 worker…',
            'stop-task' => '🛑 中止任務…',
            'tail-logs' => '📜 查看日誌…',
            'add-mcp-server' => '🔌 接入 MCP 工具…',
            'list-mcp-servers' => '🔌 盤點 MCP 工具…',
            'remove-mcp-server' => '🔌 移除 MCP 工具…',
            'generate-install-command' => '📦 產生安裝指令…',
            'add-command' => '⌨️ 新增自訂指令…',
            'list-commands' => '⌨️ 盤點自訂指令…',
            'remove-command' => '⌨️ 移除自訂指令…',
        ][$skill->name()] ?? ('🔧 '.$skill->description().'…');
    }

    /**
     * 「正在做什麼」的即時步驟說明，帶上關鍵參數讓使用者看清楚這一步在操作什麼。
     * 例如：💻 執行終端機指令：`docker exec … nginx -t`
     */
    private function stepDetail(Skill $skill, array $args): string
    {
        $label = rtrim($this->stepLabel($skill), '…');
        foreach (['cmd', 'command', 'script', 'path', 'file', 'query', 'q', 'url', 'service', 'app', 'name', 'key', 'domain'] as $k) {
            if (isset($args[$k]) && is_scalar($args[$k]) && (string) $args[$k] !== '') {
                return $label.'：'.mb_substr((string) $args[$k], 0, 140);
            }
        }

        return $this->stepLabel($skill);
    }

    /** 一步執行完的結果狀態（精簡單行預覽），即時回報給使用者。 */
    private function stepResult(Skill $skill, string $result): string
    {
        $result = (string) preg_replace('/\[\[IMG\]\]data:image\/[a-z]+;base64,[A-Za-z0-9+\/=]+/', '📸（已截圖給 AI 看）', $result);
        $r = trim($result);
        if ($r === '') {
            return '✅ 完成（無輸出）';
        }
        $preview = trim((string) preg_replace('/\s+/u', ' ', mb_substr($r, 0, 140)));
        if (mb_strpos($r, '錯誤') === 0 || str_starts_with($r, 'Error') || str_starts_with($r, 'error')) {
            return '⚠️ 失敗：'.$preview;
        }

        return '✅ 完成 · '.$preview.(mb_strlen($r) > 140 ? '…' : '');
    }

    /** 是否允許高風險自我修改：後台全域開關 OR 本對話已設「一律允許」。 */
    public function writesAllowed(Conversation $conv): bool
    {
        return (bool) $this->settings->get('skills.allow_system_writes', false) || (bool) $conv->always_allow_skills;
    }

    /**
     * 處理一則「技能類」訊息。
     *
     * @return array{reply: string, meta: array<string,mixed>}
     */
    public function handle(Conversation $conv, string $message, ?callable $onStep = null, ?callable $onDelta = null, ?callable $onThought = null): array
    {
        $step = $onStep ?? fn (string $t) => null;

        // 「一律允許 / 取消一律允許」指令（不必先有待確認操作）
        if (($toggle = $this->alwaysAllowIntent($message)) !== null) {
            $conv->update(['always_allow_skills' => $toggle, 'pending_skill' => null]);

            return [
                'reply' => $toggle
                    ? '已開啟「一律允許」🔓 —— 本對話之後的高風險操作我會直接執行，不再逐次詢問。（要收回請說「取消一律允許」）'
                    : '已關閉「一律允許」🔒 —— 高風險操作會恢復逐次確認。',
                'meta' => ['category' => 'skill', 'always_allow' => $toggle],
            ];
        }

        // 進入多輪代理迴圈：AI 可連續用工具（看結果再決定下一步），直到完成
        return $this->agentic($conv, $message, $onStep, [], $onDelta, $onThought);
    }

    /** 連續執行步數上限（避免失控）。 */
    private const MAX_ROUNDS = 6;

    /**
     * 多輪代理：每輪讓 AI 依「目前已執行結果」決定下一個工具，跑完看結果再決定下一步，
     * 直到 finish 或達上限。高風險步驟若未允許 → 暫存請求確認（確認後接續同一迴圈）。
     *
     * @param  list<array{action:string,args:array,result:string}>  $obs  已累積的觀察
     * @return array{reply: string, meta: array<string,mixed>}
     */
    private function agentic(Conversation $conv, string $message, ?callable $onStep, array $obs, ?callable $onDelta = null, ?callable $onThought = null): array
    {
        $step = $onStep ?? fn (string $t) => null;
        $picked = false;
        $forcedTool = false; // 是否已因「空談承諾」逼它選過一次工具（避免無限迴圈）
        $seen = [];           // 已執行過的 (工具+參數) 簽章，偵測重複呼叫

        // 連續操作步數上限：讀後台 react.max_steps（使用者可調），而非寫死
        // 連續操作步數上限：讀後台 react.max_steps（瀏覽器訂票/排行程等多步任務需要較高）。
        // 硬上限 60（防失控），預設見 config。
        $maxRounds = max(1, min(60, (int) $this->settings->get('react.max_steps', self::MAX_ROUNDS)));

        $plan = [];        // 代理的待辦清單（尚未完成的步驟）
        $planGuard = 0;    // 「待辦未完成卻想 finish」被擋的次數（防死循環）
        $planNote = '';    // 下一輪要注入的提醒（待辦未完成時用）

        for ($round = count($obs); $round < $maxRounds; $round++) {
            $d = $this->decide($conv, $message, $obs, $forcedTool, $onThought, $planNote);
            $planNote = '';
            // 更新待辦清單（代理每輪回報剩餘步驟）→ 即時顯示進度
            if (is_array($d['plan'] ?? null)) {
                $newPlan = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $d['plan'])));
                if ($newPlan !== $plan && $newPlan !== []) {
                    $step('📋 待辦：'.implode('｜', array_slice($newPlan, 0, 6)));
                }
                $plan = $newPlan;
            }
            $action = is_array($d) ? (string) ($d['action'] ?? 'finish') : 'finish';

            // 完成 / 沒有合適工具
            if (in_array($action, ['finish', 'none', ''], true)) {
                $final = trim((string) ($d['final'] ?? ''));

                // 防「空談承諾」：還沒實際做任何事，卻用未來式說「我來/我會去做…」
                // → 它其實該選工具去做，而不是 finish。逼它再決策一次、這次必須選工具。
                if (! $picked && ! $forcedTool && $this->isEmptyPromise($final)) {
                    $forcedTool = true;
                    $round--; // 不耗用步數

                    continue;
                }

                // 待辦清單還沒做完卻想結束 → 擋下，逼它完成剩餘項目（最多擋 3 次防死循環）
                if ($plan !== [] && $obs !== [] && $planGuard < 3) {
                    $planGuard++;
                    $planNote = '你的待辦清單還沒完成，剩下：'.implode('、', $plan)
                        .'。這一輪【絕對不可以 finish】，請直接選對應工具完成下一項待辦。';
                    $forcedTool = true;
                    $round--;

                    continue;
                }

                if (! $picked && $obs === [] && $final === '') {
                    // 第一步就判定無工具可用 → 交回對話大腦正常回答
                    return ['reply' => '', 'meta' => ['category' => 'skill', 'no_skill' => true]];
                }

                // 自我改進：用了多個工具的成功任務 → 背景萃取成可重用 playbook（下次更快）
                if (count($obs) >= 2) {
                    try { LearnSkillJob::dispatch($message, $obs); } catch (Throwable) {}
                }

                // 有實際跑過工具 → 用串流方式把「解讀結果」的最終回覆逐字輸出（即時看到 AI 輸出內容＋思考）
                if ($obs !== [] && $onDelta !== null) {
                    $reply = $this->summarize($message, $obs, $onDelta, $onThought);

                    return ['reply' => $reply, 'meta' => ['category' => 'skill', 'rounds' => count($obs), 'streamed' => true]];
                }

                return ['reply' => $final !== '' ? $final : $this->summarize($message, $obs), 'meta' => ['category' => 'skill', 'rounds' => count($obs)]];
            }

            $skill = $this->registry->get($action);
            if (! $skill) {
                $obs[] = ['action' => $action, 'args' => [], 'result' => "未知工具「{$action}」，略過"];

                continue;
            }
            $args = is_array($d['args'] ?? null) ? $d['args'] : [];
            $picked = true;

            // 重複呼叫同一工具同參數 → 已有資料，直接彙整作答（避免空轉到步數上限）
            $sig = $action.'|'.md5((string) json_encode($args, JSON_UNESCAPED_UNICODE));
            if (isset($seen[$sig])) {
                break;
            }
            $seen[$sig] = true;

            // 高風險未允許 → 暫存（含已累積 obs）、請求對話確認；確認後由 resolvePending 接續迴圈
            if ($skill->isHighRisk() && ! $this->writesAllowed($conv)) {
                $conv->update(['pending_skill' => ['skill' => $skill->name(), 'args' => $args, 'message' => $message, 'obs' => $obs]]);
                $argText = $args === [] ? '' : '（'.collect($args)->map(fn ($v, $k) => "{$k}={$v}")->implode('，').'）';

                return [
                    'reply' => "⚠️ 我接下來想執行高風險步驟：「{$skill->description()}」{$argText}\n回覆「確認」執行、「一律允許」之後都不再問、「取消」作罷。",
                    'meta' => ['category' => 'skill', 'pending' => $skill->name()],
                ];
            }

            // 重型生成型技能 → 背景執行，視為終止
            if (in_array($skill->name(), self::BACKGROUND, true)) {
                RunSkillJob::dispatch($conv->id, $skill->name(), $args);

                return ['reply' => "🧩 已開始「{$skill->description()}」（背景處理中），完成後會出現在對話。", 'meta' => ['category' => 'skill', 'skill' => $skill->name(), 'background' => true]];
            }

            // 即時回報這一步：先說「為什麼做」(thought)，再說「正在做什麼」(含關鍵參數)
            $thought = is_array($d) ? trim((string) ($d['thought'] ?? '')) : '';
            if ($thought !== '') {
                $step('🤔 '.mb_substr($thought, 0, 160));
            }
            $step($this->stepDetail($skill, $args));
            try {
                $result = $skill->run($args);
            } catch (Throwable $e) {
                $result = '錯誤：'.$e->getMessage();
            }
            // 執行完馬上回報結果狀態（精簡預覽），讓使用者看到每一步發生了什麼
            $step($this->stepResult($skill, (string) $result));
            // 截圖（[[IMG]]base64）不可截斷，否則圖會壞；其他結果照常截 3000
            $obs[] = ['action' => $skill->name(), 'args' => $args,
                'result' => str_contains((string) $result, '[[IMG]]data:image') ? (string) $result : mb_substr((string) $result, 0, 3000)];
        }

        // 達步數上限（或偵測到重複呼叫而提前結束）→ 用目前結果做總結（不顯示內部步數限制字樣）
        return ['reply' => $this->summarize($message, $obs, $onDelta, $onThought), 'meta' => ['category' => 'skill', 'rounds' => count($obs), 'streamed' => $onDelta !== null]];
    }

    /** 較重（LLM 生成型）的技能：改背景執行，避免同步阻塞數分鐘。 */
    private const BACKGROUND = ['merge-domains'];

    /**
     * 偵測「空談承諾」：AI 沒實際做事卻用未來式說「我來/我會/讓我…去做某事」。
     * 這種回覆代表它該選工具去做，而不是 finish。
     */
    private function isEmptyPromise(string $final): bool
    {
        if ($final === '') {
            return false;
        }

        // 未來式承諾語氣 + 動作動詞（安裝/執行/檢查/查看/讀取…）
        return (bool) preg_match(
            '/(我(來|會|這就|先|現在|稍後|馬上|接下來)|讓我|稍候|等我|我幫(你|您))[^。\n]{0,12}'
            .'(安裝|執行|跑|檢查|查看|查詢|看一?下|確認|讀取|抓取|搜尋|處理|試試|操作|部署|設定|查|找)/u',
            $final
        );
    }

    /** 讓 AI 依目前觀察決定下一步工具（帶最近對話脈絡，避免上下文丟失）。 */
    private function decide(Conversation $conv, string $message, array $obs, bool $forceTool = false, ?callable $onThought = null, string $planNote = ''): ?array
    {
        $catalog = $this->registry->catalog();
        $obsText = '';
        $lastImage = null; // 觀測結果裡的截圖（[[IMG]]data:URI）→ 抽出來用視覺餵給 LLM（Gemma 4 multimodal）
        foreach ($obs as $i => $o) {
            $result = (string) $o['result'];
            if (str_contains($result, '[[IMG]]data:image')) {
                if (preg_match('/\[\[IMG\]\](data:image\/[a-z]+;base64,[A-Za-z0-9+\/=]+)/', $result, $im)) {
                    $lastImage = $im[1]; // 只保留最後一張（最新畫面），避免 token 爆量
                }
                $result = trim((string) preg_replace('/\[\[IMG\]\]data:image\/[a-z]+;base64,[A-Za-z0-9+\/=]+/', '（截圖已附在下方圖片，請直接看圖判斷）', $result));
            }
            $a = json_encode($o['args'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $obsText .= ($i + 1).'. '.$o['action'].'('.$a.') → '.mb_substr($result, 0, 1500)."\n";
        }
        $obsText = $obsText !== '' ? $obsText : '（尚未執行任何步驟）';

        // 最近對話脈絡（讓代理理解多輪上下文，例如「剛剛那個」「再幫我…」）
        $history = $conv->activeMessages()->latest('id')->limit(6)->get()->reverse()
            ->map(fn ($m) => "{$m->role}: ".mb_substr((string) $m->content, 0, 300))->implode("\n");
        $mem = app(\App\Pai\Memory\UserMemoryStore::class)->recall($conv->user_id);
        $ctx = ($mem !== '' ? "（關於使用者的長期記憶，自然運用）\n{$mem}\n" : '')
            .($conv->summary ? "（先前摘要）{$conv->summary}\n" : '').($history !== '' ? $history : '（無）');

        // 自我改進：注入「以前學會的做法」（命中本次需求關鍵字）→ 讓 agent 照成功配方走，更快更穩
        $learned = LearnedSkill::relevant($message);
        $learnedHint = '';
        if ($learned->isNotEmpty()) {
            $learnedHint = "\n        【你以前學會的做法（命中本次需求，優先參考照做，可省去摸索）】\n        "
                .$learned->map(fn ($s) => "▶ {$s->name}（{$s->when_to_use}）：{$s->steps}")->implode("\n        ");
        }

        // 上一輪只「空談要做某事」卻沒選工具 → 這一輪硬性要求選出工具
        $forceNote = $forceTool
            ? "⚠️ 上一輪你只是說要做某事卻沒有選工具（空談承諾）。這一輪 action【絕對不可以是 finish】，"
                ."必須直接選出能達成使用者目標的工具去執行。\n"
            : '';
        if ($planNote !== '') {
            $forceNote .= '⚠️ '.$planNote."\n";
        }

        $nowTw = now('Asia/Taipei');
        $nowLine = $nowTw->format('Y-m-d H:i').'（週'.['日', '一', '二', '三', '四', '五', '六'][$nowTw->dayOfWeek].'，台灣時間）';
        // 預設操作節點（使用者桌面）：瀏覽器/開關程式等「要讓使用者看得到」的操作優先走這台
        $defGw = (string) app(\App\Pai\Settings\Settings::class)->get('voice.default_gateway', 'local');
        $gwHint = '';
        if ($defGw !== '' && $defGw !== 'local') {
            $gwHint = "\n        【重要】使用者的桌面節點是「{$defGw}」。需要「使用者看得到」的操作"
                ."（用瀏覽器查資料/操作網頁 browser_*、開關程式 open_app、播放音樂）"
                ."【一律優先選用 mcp__{$defGw}__ 開頭的工具】，不要用主節點(gateway)那套——"
                ."主節點是無頭伺服器，使用者看不到，瀏覽器還會被網站擋人機驗證。";
        }
        $prompt = <<<PROMPT
        你是「主動式 AI 平台」（指揮 AI）的操作代理，可「連續」使用工具達成使用者目標（例如：看磁碟滿了→再執行清理→再確認）。
        現在時間：{$nowLine}
        這個平台是個人 AI 指揮中心：語音/文字下指令，就能跨節點開關程式、執行指令、查系統狀態、播放音樂、上網查資料、跑背景任務並推播結果（Telegram/LINE/語音念回）。
        被問「你是什麼/能做什麼」時據此誠實介紹，不要說你只能做監控或資安。{$gwHint}
        【整理報告/行程/文件「輸出給使用者」時】：若使用者在手機節點，呼叫 mcp__<手機>__show_document（會在手機自動彈出顯示完整內容，比唸出來或塞進回覆好）。其餘節點可正常回覆。
        可用工具：
        {$catalog}

        最近對話脈絡：
        {$ctx}
        {$learnedHint}

        使用者目標：「{$message}」
        已執行步驟與結果：
        {$obsText}

        決定下一步，只輸出 JSON：
        {"thought":"簡述","plan":["待辦步驟1","待辦步驟2"],"action":"工具名 或 finish","args":{...},"final":"當 action=finish 時，依對話脈絡用繁體中文直接回答使用者；有工具結果就據實解讀、沒有就正常對答，不要編造"}
        重要規則：
        - **plan = 待辦清單**：把「達成使用者目標還沒做完的步驟」逐條列出（如訂機票：填出發地、填目的地、填日期、按搜尋、讀結果）。
          每完成一步就把它從 plan 移除，只保留還沒做的。**plan 還有項目時，action 絕對不可以是 finish**——要繼續選工具完成下一項。
          純閒聊或一步就能完成的事，plan 可給 []。
        - **只有在「需要真實系統資料」或「需要實際執行操作」時才用工具**。單純問答、聊天、釐清、或你不確定該用哪個工具 → 一律 action=finish，並在 final 直接回答使用者的問題（根據對話脈絡），不要硬湊工具。
        - 嚴禁為了用工具而用工具：不要用 list-domains / describe-domain 去回答與領域包無關的問題。
        - 工具的選擇必須和「使用者這次的目標」直接相關；不相關就 finish。
        - 一次一個工具；目標達成/資訊已足夠 → finish；破壞性操作前先觀察。
        - **【查資料/搜尋/找資訊 → 優先用瀏覽器實際搜尋，不要只靠 web-search】**：web-search API 抓到的常是零碎片段、排序差（它本來就只是抓 DuckDuckGo 精簡頁）。
          有節點時，優先用 mcp__<節點>__browser_navigate 開「https://www.google.com/search?q=關鍵字」讀**真正的 Google 結果頁** → browser_read 讀結果 → 需要點進某筆再 browser_click + browser_read。這樣拿到的資訊最完整準確（用瀏覽器搜的意義就是拿到 Google 真結果，不要再去搜 DuckDuckGo，否則就跟笨 API 沒兩樣）。
          web-search 工具只在沒有可用瀏覽器節點、或只需要快速粗略結果時當後備。
        - **【善用 Google 的「AI 總結 / AI Overview」】**：Google 結果頁最上方常有一段 AI 自動生成的總結（AI 概覽 / AI Overview），
          那是針對你的查詢整理好的高品質摘要，browser_read 讀回的頁面文字最前面通常就是它。**優先採用這段 AI 總結當答案的主幹**，再用底下的搜尋結果補充、核對細節，這樣又快又準。
          （若該查詢沒有出現 AI 總結，就照常讀下方的搜尋結果條目。）
        - **【操作手機 App（LINE / 任何 App）→ 用 screen_* 工具（輔助使用）】**：手機節點可以直接操作整支手機：
          mcp__<手機>__open_app 開 App → screen_snapshot 讀畫面元素（[sN] 編號＋文字）→ screen_click 點擊、screen_type 輸入、screen_swipe 滑動、screen_back 返回——每步操作後都會回最新畫面，照著繼續下一步（和瀏覽器操作同套路）。
          **回覆 LINE / 訊息的最快路徑**：notifications_list 看最近通知 → notification_reply(target=對方名字或 LINE, message=內容) 直接回覆，完全不用打開 App。
          **【在 LINE 裡傳訊息給特定的人 / 連續傳給不同人】**：每傳完一個人，要先回到「聊天列表」再找下一個人，不要以為還停在原本的聊天室：
          ① open_app LINE →（若不在聊天列表）連續 screen_back 退回主列表 → ② screen_snapshot 看聊天列表/找到對方名字；找不到就點上方「搜尋」用 screen_type 打名字 →
          ③ screen_click 點開那個人的聊天室 → ④ screen_click 點訊息輸入框 → screen_type 打字 → screen_click 點「送出/傳送」→ ⑤ screen_snapshot 確認已送出。
          **【未把訊息「打字＋送出」完成前，嚴禁 finish】**：只「打開 App」或「點進某人聊天室」都【不算完成傳訊息】——
          那只是中間步驟，務必繼續 ④ 點輸入框→screen_type 打內容→screen_click 送出鈕（找「傳送/送出/Send/紙飛機」圖示，或 screen_shot 看圖找送出鈕）→確認訊息出現在對話裡才算完成。
          找不到送出鈕時：screen_type 後按 Enter（screen_type 也可），或 screen_shot 看圖判斷送出鈕位置再 screen_click。
          **換下一個人時：務必先 screen_back 回到聊天列表、重新 screen_snapshot 找新對象**，每換一頁都重新 snapshot 拿最新編號，不要沿用舊畫面的編號。讀不懂畫面就 screen_shot 看圖。
          打電話：phone_call(to=號碼或聯絡人名稱)。播放音樂：play_music(query=歌名/歌手)。暫停/下一首：media_control。
          （這些工具需要使用者開過「通知存取」「協助工具」權限；工具回覆若說未開啟，把那段話轉告使用者請他開啟。）
          **【你看得懂圖片】**：screen_snapshot 元素讀不懂、畫面是圖片/地圖/影片、或不確定畫面長怎樣時，呼叫 screen_shot——
          截圖會直接附給你「看」，照你看到的內容判斷下一步（要點哪裡就用 screen_click 配可見文字或先 snapshot 拿編號）。
        - **【多步驟、會重複、要組裝資料的任務 → 用 execute-code 一次做完】**：與其一輪一個工具來回（本地模型慢），
          可寫一段 PHP 用 tool('技能名',[參數]) 連續呼叫多個工具＋迴圈/條件/組裝，一輪收斂。
          例：要查 3 個地點的車程 → 一段程式跑迴圈呼叫 3 次；要彙整多筆搜尋 → 一段程式搜完直接組成結果 return。
          但「需要看每一步結果才能決定下一步」的探索型任務（如操作未知網頁/App）仍照常一步一工具。
        - **【一次只查一個主題，不要把多個問題塞進同一個搜尋字串】**：搜尋引擎一次搜一件事最準。
          若使用者一句話包含多個要查的點（例：「汐止到台中車程」「山河滷肉飯營業時間」「台中兩日遊行程」），請把它們拆成 plan 裡的**獨立步驟**，
          一個 browser_navigate（搜尋第一個）→ browser_read 讀完 → 再 browser_navigate（搜尋第二個）→ browser_read…逐一查完，最後彙整。
          **絕對不要**用「A+B+C」或「A B C」把多個不相關問題串成一個 q= 查詢（會搜出一堆無關雜訊）。
        - **【訂票/訂房/排行程/比價/在特定網站操作 → 一律用瀏覽器實際操作，不要只靠 web-search 看文字】**：
          用 browser_navigate 開對應網站（訂機票→Google Flights 或航空公司官網；訂房→Booking/Agoda；排行程→Google Maps）→ browser_snapshot 看可點/可填元素 → browser_type 填日期/地點/條件、browser_click 點搜尋/選項 → browser_read 讀結果。一步一步真的操作到位。
          **browser_click / browser_type 的 target 請優先用 snapshot 列出的元素編號（如 e10），不要用整段文字描述**——編號最準。每次頁面變化（彈窗出現/換頁）都要重新 browser_snapshot 拿最新編號再操作。
          **涉及付款、最終送出、確認下單前一定要停下來**：把目前填好的內容與選項用 final 回報給使用者，請他確認，【絕對不要自動完成付款或送出訂單】。
          這類「要讓使用者看得到」的瀏覽器操作優先用桌面節點（見上方說明）的 browser_* 工具。
          **瀏覽器操作遇到「⚠️ 逾時/失敗」或結果出現彈窗時，絕對不要放棄 finish**：那通常是頁面彈出視窗或載入中。
          請改用回傳的『當前可互動元素』重新選擇目標（例如先關掉彈窗、或在彈窗的輸入框打字），一步步繼續，直到真的完成目標。
          **頁面空白/元素很少/沒載好時：先 browser_reload 重新載入一次再 browser_snapshot**，通常就會出現。
          **【要「在地圖上顯示路線/導航」→ 一律用 maps_route 工具開原生 Google 地圖 App，不要用內建瀏覽器開 Maps】**：
          手機內建瀏覽器（WebView）渲染不出 Google Maps 的地圖本體（會一片空白），所以使用者說「打開地圖把路線排上去 / 在地圖顯示路線 / 幫我導航」時，
          直接呼叫 mcp__<手機節點>__maps_route(origin, destination, waypoints, mode)——它會叫出原生地圖 App，地圖和路線一定渲染得出來。
          多個停點（例：汐止→山河滷肉飯→台中飯店）就把中間點放進 waypoints（用「|」分隔）。origin 留空＝從使用者目前位置出發。
          若只是要「車程時間/距離」這種純【資料】，才用 Google 搜尋讀 AI 摘要；要看地圖就用 maps_route。
          **【嚴禁捏造頁面內容 — 最重要】**：你對網頁的「所有認知」都只能來自 browser_read / browser_snapshot 工具實際回傳的文字。
          若工具回傳逾時、空白或錯誤，代表你「沒讀到」——此時**先重試**（再 browser_read 一次、或等一下再讀、或重新 browser_navigate），通常第二次就成功。
          **絕對不可以**在沒讀到內容時，自己想像、推測、編造頁面上有什麼（例如「出現 Google Sorry 驗證頁」「被反機器人攔截」「顯示 XXX 結果」都是嚴重錯誤，除非工具回傳的文字裡真的有這些字）。
          重試多次仍讀不到，才如實告訴使用者「這個頁面目前讀取逾時、沒能取得內容」，不要假裝讀到了。
          **【完成條件，未達成前嚴禁 finish】**：
          - 訂機票：必須依序「填出發地 → 填目的地 → 填出發日期（來回票再填回程）→ 按搜尋 → 等班機結果出現 → 讀取結果」全部做完。
            只填了出發地（或只填一半欄位、還沒按搜尋、還沒看到班機）就 finish 是【嚴重錯誤】。
          - 訂房：填地點 → 入住/退房日期 → 人數 → 搜尋 → 看到房型結果，才算完成。
          每完成一格就 browser_snapshot 看下一個要填/點的元素在哪，繼續下一步；填完一個欄位不代表完成，要把整個搜尋流程走到「結果頁」為止。
        - **【最重要】禁止「空談承諾」**：絕對不要在 final 用未來式說「好，我來執行…」「我來檢查一下…」「讓我跑一下…」「我幫你看…」這種【說要做卻沒做】的話。
          如果達成目標需要實際動作（安裝、跑指令、檢查狀態、讀檔、查日誌、確認結果…），你【現在這一步就直接選對應工具去做】（例如安裝/檢查狀態→run-shell，讀檔→read-file），不要 finish 空講。
          只有在「動作已真的做完、結果已在上面」或「純聊天不需動作」時才 finish。
        {$forceNote}/no_think
        PROMPT;

        // 有截圖 → 用視覺格式（OpenAI content parts）讓 LLM 直接「看」畫面（Gemma 4 multimodal）
        $userContent = $lastImage !== null
            ? [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $lastImage]],
            ]
            : $prompt;

        try {
            // 帶 onThought → 串流模式：把推理鏈即時推給前端，JSON 內容靜默累積後解析
            if ($onThought !== null) {
                $full = '';
                $this->llm->stream(
                    [['role' => 'user', 'content' => $userContent]],
                    function (string $d) use (&$full) { $full .= $d; }, // 累積 JSON，不外露給使用者
                    null,
                    null,
                    $onThought // reasoning_content 即時串流
                );

                return LlmClient::extractJson($full);
            }

            return LlmClient::extractJson($this->llm->chat([['role' => 'user', 'content' => $userContent]], ['max_tokens' => 1024]));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 根據累積結果產生最終自然語言回覆。
     * 帶 $onDelta 時逐 token 串流輸出（讓前端即時看到 AI 在打字），並回傳完整文字。
     */
    private function summarize(string $message, array $obs, ?callable $onDelta = null, ?callable $onThought = null): string
    {
        if ($obs === []) {
            return '我沒有可執行的步驟。';
        }
        $results = collect($obs)->map(function ($o) {
            $r = (string) preg_replace('/\[\[IMG\]\]data:image\/[a-z]+;base64,[A-Za-z0-9+\/=]+/', '（截圖）', (string) $o['result']);

            return $o['action'].' → '.mb_substr($r, 0, 1500);
        })->implode("\n");
        $messages = [
            ['role' => 'system', 'content' => '根據以下工具實際執行結果，用繁體中文回答使用者目標的結論與重點；只依結果、不要編造。'],
            ['role' => 'user', 'content' => "目標：{$message}\n結果：\n{$results}"],
        ];
        try {
            if ($onDelta !== null) {
                // 串流：逐 token 推給前端（含思考過程），同時累積完整內容
                $full = '';
                $this->llm->stream(
                    $messages,
                    function (string $delta) use (&$full, $onDelta) {
                        $full .= $delta;
                        $onDelta($delta);
                    },
                    null,
                    null,
                    $onThought // reasoning_content 即時串流
                );
                $r = trim($full);
            } else {
                $r = trim($this->llm->chat($messages));
            }
            if ($r !== '') {
                return $r;
            }
        } catch (Throwable) {
            // ignore
        }

        return $results;
    }

    /**
     * 會話有待確認技能時，依使用者回覆決定執行/取消。回傳 null 表示這不是確認/取消訊息。
     *
     * @return array{reply: string, meta: array<string,mixed>}|null
     */
    public function resolvePending(Conversation $conv, string $message, ?callable $onStep = null, ?callable $onDelta = null, ?callable $onThought = null): ?array
    {
        $pending = $conv->pending_skill;
        if (! is_array($pending) || empty($pending['skill'])) {
            return null;
        }
        $verdict = $this->confirmation($message);
        if ($verdict === null) {
            return null; // 既非確認也非取消 → 當作新訊息處理（保留待確認）
        }

        if ($verdict === false) {
            $conv->update(['pending_skill' => null]);

            return ['reply' => '好的，已取消這次操作。', 'meta' => ['category' => 'skill', 'cancelled' => true]];
        }
        // 'always' → 本對話之後一律允許
        $conv->update(['pending_skill' => null, 'always_allow_skills' => $verdict === 'always' ? true : $conv->always_allow_skills]);

        $skill = $this->registry->get($pending['skill']);
        if (! $skill) {
            return ['reply' => '原本要執行的技能已不存在，已取消。', 'meta' => ['category' => 'skill']];
        }
        $message = $pending['message'] ?? '';
        $obs = is_array($pending['obs'] ?? null) ? $pending['obs'] : [];
        $step = $onStep ?? fn (string $t) => null;

        // 執行這個被確認的高風險步驟（即時回報）
        $step($this->stepDetail($skill, $pending['args'] ?? []).'（已核准，執行中…）');
        try {
            $result = $skill->run($pending['args'] ?? []);
        } catch (Throwable $e) {
            $result = '錯誤：'.$e->getMessage();
        }
        $step($this->stepResult($skill, (string) $result));
        $obs[] = ['action' => $skill->name(), 'args' => $pending['args'] ?? [],
            'result' => str_contains((string) $result, '[[IMG]]data:image') ? (string) $result : mb_substr((string) $result, 0, 3000)];

        // 接續多輪代理迴圈（confirm 一次後若還有後續步驟，會繼續；'always' 則之後不再問）
        $out = $this->agentic($conv, $message, $onStep, $obs, $onDelta, $onThought);
        if ($verdict === 'always') {
            $out['reply'] = "🔓（已開啟本對話「一律允許」）\n".$out['reply'];
        }

        return $out;
    }

    /**
     * 判斷待確認回覆：'always'(一律允許) / true(確認一次) / false(取消) / null(不確定)。
     * 先用關鍵字，避免多一次 LLM。
     */
    private function confirmation(string $message): string|bool|null
    {
        $m = mb_strtolower(trim($message));
        // 先判「一律允許」（比單純「允許」更優先）
        if ($this->alwaysAllowIntent($message) === true) {
            return 'always';
        }
        $no = ['取消', '不要', '不用', '否', '別', 'no', 'n', '算了'];
        foreach ($no as $w) {
            if (str_contains($m, $w)) {
                return false;
            }
        }
        $yes = ['確認', '確定', '是', '好', '可以', '同意', '允許', '執行', 'ok', 'yes', 'y', '對'];
        foreach ($yes as $w) {
            if (str_contains($m, $w)) {
                return true;
            }
        }

        return null;
    }

    /** 偵測「一律允許 / 取消一律允許」意圖：true=開、false=關、null=非此意圖。 */
    private function alwaysAllowIntent(string $message): ?bool
    {
        $m = mb_strtolower(trim($message));
        $off = ['取消一律', '關閉一律', '關閉自動允許', '取消自動允許', '不要一律', '恢復確認', 'stop always'];
        foreach ($off as $w) {
            if (str_contains($m, $w)) {
                return false;
            }
        }
        $on = ['一律允許', '都允許', '全部允許', '永遠允許', '自動允許', '免確認', '不要再問', '不用再問', 'always allow', 'auto approve'];
        foreach ($on as $w) {
            if (str_contains($m, $w)) {
                return true;
            }
        }

        return null;
    }
}
