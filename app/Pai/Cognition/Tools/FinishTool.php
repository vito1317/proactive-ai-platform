<?php

namespace App\Pai\Cognition\Tools;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/** 結束本次運行並給出總結。 */
final class FinishTool implements Tool
{
    public function name(): string
    {
        return 'finish';
    }

    public function description(): string
    {
        return '完成處理並總結。action_input: {"summary": "處理總結"}。當已記錄發現且提出必要動作後呼叫。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $ctx->summary = trim((string) ($input['summary'] ?? '處理完成。'));
        $ctx->finished = true;

        return ToolResult::ok('運行結束。');
    }
}
