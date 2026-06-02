<?php

namespace App\Pai\Cognition\Tools;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/** 取得觸發此次運行的事件全貌（主題 / 意圖 / 嚴重性 / 原始負載）。 */
final class GetEventContextTool implements Tool
{
    public function name(): string
    {
        return 'get_event_context';
    }

    public function description(): string
    {
        return '取得觸發事件的完整內容（topic, intent, severity, payload）。無需參數。通常作為第一步。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $e = $ctx->event;

        return ToolResult::ok(json_encode([
            'topic' => $e->topic,
            'source' => $e->source,
            'intent' => $e->intent,
            'severity' => $e->severity?->value,
            'payload' => $e->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
