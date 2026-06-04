<?php

namespace App\Pai\Cognition\Tools;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/** 記錄一條分析發現（會顯示在中控台的運行軌跡）。 */
final class RecordFindingTool implements Tool
{
    public function name(): string
    {
        return 'record_finding';
    }

    public function description(): string
    {
        return '記錄一條分析發現或結論。action_input: {"finding": "簡述發現"}。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $finding = trim((string) ($input['finding'] ?? ''));
        if ($finding === '') {
            return ToolResult::fail('finding 不可為空。');
        }
        $ctx->addFinding($finding);

        return ToolResult::ok('已記錄發現。目前共 '.count($ctx->findings).' 條。');
    }
}
