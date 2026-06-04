<?php

namespace App\Pai\Cognition\Tools\DevAuto;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/**
 * 提出對某檔案的修補。不直接寫入 repo——記錄為高風險動作，
 * 套用 / 合併到 main 需人類核准 (L5)。dev-auto 預設 copilot，故一律待審。
 */
final class ProposePatchTool implements Tool
{
    public function name(): string
    {
        return 'propose_patch';
    }

    public function description(): string
    {
        return '提出修補方案。action_input: {"path":"檔案", "summary":"改什麼", "patch":"修正後的『完整檔案內容』"}。不會直接套用；經人類核准後才會寫回 repo 並重跑測試。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        $summary = trim((string) ($input['summary'] ?? ''));
        $patch = trim((string) ($input['patch'] ?? ''));
        if ($path === '' || $summary === '') {
            return ToolResult::fail('需要 path 與 summary。');
        }

        // 修補內容存進發現，供人類審查
        $ctx->addFinding("提議修補 `{$path}`：{$summary}".($patch !== '' ? "\n```\n".mb_substr($patch, 0, 1200)."\n```" : ''));
        // 套用修補為高風險動作 → 進 HITL；payload 帶完整檔案內容供核准後寫回
        $ctx->addAction("apply-patch:{$path}", $summary, 'high', ['path' => $path, 'patch' => $patch]);

        return ToolResult::ok("已提出對 {$path} 的修補（高風險，待人類核准後套用）。");
    }
}
