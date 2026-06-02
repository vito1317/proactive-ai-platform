<?php

namespace App\Pai\Cognition\Tools;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/**
 * 提出一個要執行的動作。風險由領域 risk_policy 決定——
 * 列入 hitl_required 的動作會被標為高風險，後續由 L5 護欄擋下等待人類核准。
 */
final class ProposeActionTool implements Tool
{
    public function name(): string
    {
        return 'propose_action';
    }

    public function description(): string
    {
        return '提出一個處置動作。action_input: {"action": "動作鍵", "rationale": "理由"}。'
            .'高風險動作會自動進入人類審核。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $action = trim((string) ($input['action'] ?? ''));
        $rationale = trim((string) ($input['rationale'] ?? ''));
        if ($action === '') {
            return ToolResult::fail('action 不可為空。');
        }

        // 風險判定：列入 hitl_required → high；否則採模型提示或預設 medium/low
        $risk = $ctx->pack->isHitlAction($action)
            ? 'high'
            : (in_array($input['risk'] ?? null, ['low', 'medium', 'high'], true) ? $input['risk'] : 'low');

        $ctx->addAction($action, $rationale, $risk);

        $gate = $risk === 'high' ? '（高風險，將送人類審核）' : '（可自動執行）';

        return ToolResult::ok("已提出動作「{$action}」，風險={$risk}{$gate}。");
    }
}
