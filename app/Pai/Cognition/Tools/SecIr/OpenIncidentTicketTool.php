<?php

namespace App\Pai\Cognition\Tools\SecIr;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/**
 * 開立事件工單。屬中風險動作——sec-ir 為 supervisor 時可自動執行（非破壞性），
 * 讓 L4「行動」亮起；破壞性遏制則走 propose_containment → HITL。
 */
final class OpenIncidentTicketTool implements Tool
{
    public function name(): string
    {
        return 'open_incident_ticket';
    }

    public function description(): string
    {
        return '開立事件工單以追蹤處置。action_input: {"summary":"一句話描述"}。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $summary = trim((string) ($input['summary'] ?? ''));
        if ($summary === '') {
            return ToolResult::fail('需要 summary。');
        }

        $ticket = 'INC-'.str_pad((string) $ctx->event->id, 5, '0', STR_PAD_LEFT);
        $ctx->addFinding("已開立工單 {$ticket}：{$summary}");
        $ctx->addAction("open-ticket:{$ticket}", $summary, 'medium');

        return ToolResult::ok("已開立事件工單 {$ticket}（中風險，supervisor 下自動執行）。");
    }
}
