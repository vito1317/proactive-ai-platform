<?php

namespace App\Pai\Cognition\Tools;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;
use App\Pai\Domains\DomainRegistry;

/**
 * A2A 跨域交辦：把不屬於本領域職責的工作交給另一個領域協調者
 * （例如 sec-ir 發現程式漏洞 → 交給 dev-auto 修補）。
 *
 * 純記錄（不立即派發），實際派發於迴圈結束後由引擎執行一次，故可安全重放。
 */
final class HandoffTool implements Tool
{
    public function __construct(private readonly DomainRegistry $registry) {}

    public function name(): string
    {
        return 'handoff_to_domain';
    }

    public function description(): string
    {
        $others = implode(' / ', array_keys($this->registry->all()));

        return "把工作交辦給另一個領域協調者處理。action_input: {\"to_domain\":\"領域鍵\", \"task\":\"要做什麼\", \"artifact\":{...結構化資料}}。可用領域：{$others}。";
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $to = trim((string) ($input['to_domain'] ?? ''));
        $task = trim((string) ($input['task'] ?? ''));
        $artifact = is_array($input['artifact'] ?? null) ? $input['artifact'] : [];

        if ($to === '' || $task === '') {
            return ToolResult::fail('需要 to_domain 與 task。');
        }
        if ($to === $ctx->pack->domain) {
            return ToolResult::fail('不可交辦給自己。');
        }
        if (! $this->registry->has($to)) {
            return ToolResult::fail("未知領域：{$to}。可用：".implode(', ', array_keys($this->registry->all())));
        }

        $ctx->addHandoff($to, $task, $artifact);

        return ToolResult::ok("已安排交辦給「{$to}」：{$task}（迴圈結束後派發）。");
    }
}
