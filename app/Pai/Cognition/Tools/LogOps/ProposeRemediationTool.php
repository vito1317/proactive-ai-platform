<?php

namespace App\Pai\Cognition\Tools\LogOps;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/**
 * 提出修復動作。低風險（如 clear-cache）在 supervisor 下自動執行；
 * 破壞性（restart-service / rollback-deploy / delete-data / scale-down，列於 hitl_required）一律待人類核准。
 */
final class ProposeRemediationTool implements Tool
{
    /** 已知破壞性動作（會被 risk_policy 擋到 HITL）。 */
    private const DESTRUCTIVE = ['restart-service', 'rollback-deploy', 'delete-data', 'scale-down'];

    public function name(): string
    {
        return 'propose_remediation';
    }

    public function description(): string
    {
        return '提出修復動作。action_input: {"action":"如 clear-cache / restart-service", "target":"對象(選填)", "rationale":"理由"}。破壞性動作須人類核准。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $action = trim((string) ($input['action'] ?? ''));
        $target = trim((string) ($input['target'] ?? ''));
        $rationale = trim((string) ($input['rationale'] ?? ''));
        if ($action === '') {
            return ToolResult::fail('需要 action。');
        }

        $risk = in_array($action, self::DESTRUCTIVE, true) ? 'high' : 'low';
        $ctx->addAction($action, trim("{$target}：{$rationale}", '：'), $risk, ['target' => $target]);

        $gate = $risk === 'high' ? '（高風險，待人類核准）' : '（低風險，supervisor 下自動執行）';

        return ToolResult::ok("已提出修復「{$action}」{$gate}。");
    }
}
