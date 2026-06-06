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

    /** 把技能對應成一句「正在做什麼」的步驟說明（給前端活動軌跡）。 */
    private function stepLabel(Skill $skill): string
    {
        return [
            'web-search' => '🔍 上網搜尋…',
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
        $maxRounds = max(1, min(20, (int) $this->settings->get('react.max_steps', self::MAX_ROUNDS)));

        for ($round = count($obs); $round < $maxRounds; $round++) {
            $d = $this->decide($conv, $message, $obs, $forcedTool, $onThought);
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

                if (! $picked && $obs === [] && $final === '') {
                    // 第一步就判定無工具可用 → 交回對話大腦正常回答
                    return ['reply' => '', 'meta' => ['category' => 'skill', 'no_skill' => true]];
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
            $obs[] = ['action' => $skill->name(), 'args' => $args, 'result' => mb_substr((string) $result, 0, 3000)];
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
    private function decide(Conversation $conv, string $message, array $obs, bool $forceTool = false, ?callable $onThought = null): ?array
    {
        $catalog = $this->registry->catalog();
        $obsText = '';
        foreach ($obs as $i => $o) {
            $a = json_encode($o['args'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $obsText .= ($i + 1).'. '.$o['action'].'('.$a.') → '.mb_substr((string) $o['result'], 0, 1500)."\n";
        }
        $obsText = $obsText !== '' ? $obsText : '（尚未執行任何步驟）';

        // 最近對話脈絡（讓代理理解多輪上下文，例如「剛剛那個」「再幫我…」）
        $history = $conv->activeMessages()->latest('id')->limit(6)->get()->reverse()
            ->map(fn ($m) => "{$m->role}: ".mb_substr((string) $m->content, 0, 300))->implode("\n");
        $ctx = ($conv->summary ? "（先前摘要）{$conv->summary}\n" : '').($history !== '' ? $history : '（無）');

        // 上一輪只「空談要做某事」卻沒選工具 → 這一輪硬性要求選出工具
        $forceNote = $forceTool
            ? "⚠️ 上一輪你只是說要做某事卻沒有選工具（空談承諾）。這一輪 action【絕對不可以是 finish】，"
                ."必須直接選出能達成使用者目標的工具去執行。\n"
            : '';

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
        可用工具：
        {$catalog}

        最近對話脈絡：
        {$ctx}

        使用者目標：「{$message}」
        已執行步驟與結果：
        {$obsText}

        決定下一步，只輸出 JSON：
        {"thought":"簡述","action":"工具名 或 finish","args":{...},"final":"當 action=finish 時，依對話脈絡用繁體中文直接回答使用者；有工具結果就據實解讀、沒有就正常對答，不要編造"}
        重要規則：
        - **只有在「需要真實系統資料」或「需要實際執行操作」時才用工具**。單純問答、聊天、釐清、或你不確定該用哪個工具 → 一律 action=finish，並在 final 直接回答使用者的問題（根據對話脈絡），不要硬湊工具。
        - 嚴禁為了用工具而用工具：不要用 list-domains / describe-domain 去回答與領域包無關的問題。
        - 工具的選擇必須和「使用者這次的目標」直接相關；不相關就 finish。
        - 一次一個工具；目標達成/資訊已足夠 → finish；破壞性操作前先觀察。
        - **【最重要】禁止「空談承諾」**：絕對不要在 final 用未來式說「好，我來執行…」「我來檢查一下…」「讓我跑一下…」「我幫你看…」這種【說要做卻沒做】的話。
          如果達成目標需要實際動作（安裝、跑指令、檢查狀態、讀檔、查日誌、確認結果…），你【現在這一步就直接選對應工具去做】（例如安裝/檢查狀態→run-shell，讀檔→read-file），不要 finish 空講。
          只有在「動作已真的做完、結果已在上面」或「純聊天不需動作」時才 finish。
        {$forceNote}/no_think
        PROMPT;

        try {
            // 帶 onThought → 串流模式：把推理鏈即時推給前端，JSON 內容靜默累積後解析
            if ($onThought !== null) {
                $full = '';
                $this->llm->stream(
                    [['role' => 'user', 'content' => $prompt]],
                    function (string $d) use (&$full) { $full .= $d; }, // 累積 JSON，不外露給使用者
                    null,
                    null,
                    $onThought // reasoning_content 即時串流
                );

                return LlmClient::extractJson($full);
            }

            return LlmClient::extractJson($this->llm->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 1024]));
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
        $results = collect($obs)->map(fn ($o) => $o['action'].' → '.mb_substr((string) $o['result'], 0, 1500))->implode("\n");
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
        $obs[] = ['action' => $skill->name(), 'args' => $pending['args'] ?? [], 'result' => mb_substr((string) $result, 0, 3000)];

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
