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

    /** 是否全域允許高風險自我修改（後台開關 / autopilot）。 */
    public function writesAllowed(): bool
    {
        return (bool) $this->settings->get('skills.allow_system_writes', false);
    }

    /**
     * 處理一則「技能類」訊息。
     *
     * @return array{reply: string, meta: array<string,mixed>}
     */
    public function handle(Conversation $conv, string $message): array
    {
        $pick = $this->pick($message);
        $skill = $pick ? $this->registry->get($pick['skill']) : null;
        if (! $skill) {
            return ['reply' => '我看得出你想操作平台，但無法對應到具體技能。可用技能：'
                .implode('、', array_keys($this->registry->all())), 'meta' => ['category' => 'skill']];
        }
        $args = $pick['args'] ?? [];

        // 低風險或已全域允許 → 直接執行
        if (! $skill->isHighRisk() || $this->writesAllowed()) {
            return $this->execute($conv, $skill, $args);
        }

        // 高風險且未全域允許 → 暫存、要求對話確認
        $conv->update(['pending_skill' => ['skill' => $skill->name(), 'args' => $args]]);
        $argText = $args === [] ? '' : '（'.collect($args)->map(fn ($v, $k) => "{$k}={$v}")->implode('，').'）';

        return [
            'reply' => "⚠️ 這是高風險操作，會修改平台本身：\n「{$skill->description()}」{$argText}\n\n確定要執行嗎？回覆「確認」我就執行，回覆「取消」則作罷。",
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

        $conv->update(['pending_skill' => null]);
        if ($verdict === false) {
            return ['reply' => '好的，已取消這次操作。', 'meta' => ['category' => 'skill', 'cancelled' => true]];
        }
        $skill = $this->registry->get($pending['skill']);
        if (! $skill) {
            return ['reply' => '原本要執行的技能已不存在，已取消。', 'meta' => ['category' => 'skill']];
        }

        return $this->execute($conv, $skill, $pending['args'] ?? []);
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

    /** 判斷確認(true)/取消(false)/不確定(null)。先用關鍵字，避免多一次 LLM。 */
    private function confirmation(string $message): ?bool
    {
        $m = mb_strtolower(trim($message));
        $yes = ['確認', '確定', '是', '好', '可以', '同意', '允許', '執行', 'ok', 'yes', 'y', '對'];
        $no = ['取消', '不要', '不用', '否', '別', 'no', 'n', '算了'];
        foreach ($yes as $w) {
            if (str_contains($m, $w)) {
                return true;
            }
        }
        foreach ($no as $w) {
            if (str_contains($m, $w)) {
                return false;
            }
        }

        return null;
    }
}
