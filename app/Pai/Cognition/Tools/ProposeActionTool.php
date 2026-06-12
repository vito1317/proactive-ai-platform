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
        return '提出一個處置動作。action_input: {"action": "動作鍵", "rationale": "理由", "confidence": 0~1 你對此處置的把握}。'
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

        // 信心：模型自報（0~1）；沒給用 0.7（治理層 gate 會用到）
        $confidence = is_numeric($input['confidence'] ?? null) ? (float) $input['confidence'] : 0.7;

        $ctx->addAction($action, $rationale, $risk, [], $confidence);

        $gate = $risk === 'high' ? '（高風險，將送人類審核）' : '（可自動執行）';

        return ToolResult::ok("已提出動作「{$action}」，風險={$risk}{$gate}。");
    }
}
