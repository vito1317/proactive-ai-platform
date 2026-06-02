<?php

namespace App\Pai\Cognition\Tools\LogOps;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/**
 * 比對已知錯誤 → 修復對照表（log-ops 的 runbook / L2 知識）。
 * 回傳建議的修復動作鍵與風險，協助 remediator 提出處置。
 */
final class MatchRunbookTool implements Tool
{
    /** regex => [建議動作, 說明]。 */
    private const RUNBOOK = [
        'connection refused|could not connect|connection timed out|connection reset' => ['restart-service', '服務可能停擺，重啟服務或檢查網路連線'],
        'no space|disk full|enospc' => ['clear-cache', '磁碟空間不足，清理快取/暫存檔'],
        'permission denied|eacces|not writable' => ['fix-permissions', '權限不足，修正目標檔案/目錄權限'],
        'out of memory|allowed memory size|oom' => ['restart-service', '記憶體耗盡，重啟服務並檢視記憶體上限'],
        'sqlstate|deadlock|too many connections|database' => ['restart-service', '資料庫連線異常，重啟服務或檢查連線池'],
        'rate limit|429|throttle' => ['clear-cache', '達到速率限制，稍候重試或清除暫存佇列'],
        'exception|stack trace|fatal error|typeerror|undefined|syntaxerror' => ['handoff:dev-auto', '程式碼層級錯誤，建議交辦 dev-auto 修補'],
    ];

    public function name(): string
    {
        return 'match_runbook';
    }

    public function description(): string
    {
        return '比對已知錯誤的修復對照表。action_input: {"error":"錯誤訊息"}，預設用觸發事件內容。回傳建議動作鍵與說明。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $text = trim((string) ($input['error'] ?? ''));
        if ($text === '') {
            $text = (string) ($ctx->event->payload['excerpt'] ?? $ctx->event->payload['line'] ?? '');
        }

        $hits = [];
        foreach (self::RUNBOOK as $pattern => [$action, $hint]) {
            if (preg_match('/'.$pattern.'/i', $text)) {
                $hits[$action] = "建議 {$action} — {$hint}";
            }
        }

        if ($hits === []) {
            return ToolResult::ok('runbook 無對應條目；建議人工研判或交辦處理。');
        }

        return ToolResult::ok("runbook 命中：\n".implode("\n", $hits));
    }
}
