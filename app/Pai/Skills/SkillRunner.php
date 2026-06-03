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
        ][$skill->name()] ?? ('🔧 '.$skill->description().'…');
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
    public function handle(Conversation $conv, string $message, ?callable $onStep = null): array
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

        $step('🧠 判斷要用哪個能力…');
        $pick = $this->pick($message);
        $skill = $pick ? $this->registry->get($pick['skill']) : null;
        if (! $skill) {
            // 對應不到具體技能 → 交回對話大腦正常回答（而非丟一串技能清單）
            return ['reply' => '', 'meta' => ['category' => 'skill', 'no_skill' => true]];
        }
        $args = $pick['args'] ?? [];
        $step($this->stepLabel($skill));

        // 低風險或已允許（全域 / 本對話一律允許）→ 直接執行
        if (! $skill->isHighRisk() || $this->writesAllowed($conv)) {
            return $this->execute($conv, $skill, $args);
        }

        // 高風險且未允許 → 暫存、要求對話確認
        $conv->update(['pending_skill' => ['skill' => $skill->name(), 'args' => $args]]);
        $argText = $args === [] ? '' : '（'.collect($args)->map(fn ($v, $k) => "{$k}={$v}")->implode('，').'）';

        return [
            'reply' => "⚠️ 這是高風險操作，會修改系統：\n「{$skill->description()}」{$argText}\n\n確定要執行嗎？回覆「確認」執行一次、「一律允許」之後都不再問、「取消」則作罷。",
            'meta' => ['category' => 'skill', 'pending' => $skill->name()],
        ];
    }

    /**
     * 會話有待確認技能時，依使用者回覆決定執行/取消。回傳 null 表示這不是確認/取消訊息。
     *
     * @return array{reply: string, meta: array<string,mixed>}|null
     */
    public function resolvePending(Conversation $conv, string $message): ?array
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
        $result = $this->execute($conv, $skill, $pending['args'] ?? []);
        if ($verdict === 'always') {
            $result['reply'] = "🔓（已開啟本對話「一律允許」，之後不再逐次詢問）\n".$result['reply'];
        }

        return $result;
    }

    private function execute(Conversation $conv, Skill $skill, array $args): array
    {
        try {
            $reply = $skill->run($args);
        } catch (Throwable $e) {
            $reply = "執行技能「{$skill->name()}」時發生錯誤：".$e->getMessage();
        }

        return ['reply' => $reply, 'meta' => ['category' => 'skill', 'skill' => $skill->name()]];
    }

    /** LLM：把訊息對應到技能 + 參數。 */
    private function pick(string $message): ?array
    {
        $catalog = $this->registry->catalog();
        $prompt = <<<PROMPT
        你是平台操作路由器。根據使用者訊息，從下列技能挑出最合適的一個並填入參數。
        技能清單：
        {$catalog}

        只輸出 JSON：{"skill":"技能名稱或 none","args":{...}}
        若沒有合適技能輸出 {"skill":"none","args":{}}。
        使用者訊息：「{$message}」
        PROMPT;

        try {
            $out = LlmClient::extractJson($this->llm->chat([['role' => 'user', 'content' => $prompt]]));
        } catch (Throwable) {
            return null;
        }
        if (($out['skill'] ?? 'none') === 'none') {
            return null;
        }

        return ['skill' => (string) $out['skill'], 'args' => is_array($out['args'] ?? null) ? $out['args'] : []];
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
