<?php

namespace App\Pai\Cognition\Tools\SecIr;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/**
 * 提出遏制動作（隔離主機 / 封鎖 IP / 停用帳號）。皆為破壞性高風險動作，
 * 列於 sec-ir 的 hitl_required → 一律送人類核准 (L5)。真正執行時會經
 * EgressGateway 注入 EDR/防火牆憑證。
 */
final class ProposeContainmentTool implements Tool
{
    private const ALLOWED = ['isolate-host', 'firewall.block', 'idp.disable-account'];

    public function name(): string
    {
        return 'propose_containment';
    }

    public function description(): string
    {
        return '提出遏制動作。action_input: {"action":"isolate-host|firewall.block|idp.disable-account", "target":"主機/IP/帳號", "rationale":"理由"}。高風險，須人類核准。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $action = trim((string) ($input['action'] ?? ''));
        $target = trim((string) ($input['target'] ?? ''));
        $rationale = trim((string) ($input['rationale'] ?? ''));

        if (! in_array($action, self::ALLOWED, true)) {
            return ToolResult::fail('action 須為：'.implode(' / ', self::ALLOWED));
        }

        $ctx->addAction($action, trim("{$target}：{$rationale}", '：'), 'high', ['target' => $target]);

        return ToolResult::ok("已提出遏制動作「{$action}」對「{$target}」（高風險，待人類核准後經 EgressGateway 執行）。");
    }
}
