<?php

namespace App\Pai\Cognition\Tools\LogOps;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/** 讀取受監控日誌檔的近期內容（唯讀，僅限設定中的來源）。 */
final class ReadLogContextTool implements Tool
{
    public function name(): string
    {
        return 'read_log_context';
    }

    public function description(): string
    {
        return '讀取日誌檔末端的近期內容以了解更多上下文。action_input: {"file":"檔名"}，預設用觸發事件的來源。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $sources = (array) config('pai.logops.sources');
        $wanted = (string) ($input['file'] ?? ($ctx->event->payload['path'] ?? ''));

        // 僅允許讀取設定中的來源（依完整路徑或檔名比對）
        $path = null;
        foreach ($sources as $s) {
            if ($s === $wanted || basename($s) === basename($wanted)) {
                $path = $s;
                break;
            }
        }
        if ($path === null || ! is_file($path)) {
            return ToolResult::fail('找不到或不允許的日誌來源。');
        }

        $lines = array_slice(preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [], -40);

        return ToolResult::ok("近期日誌（{$path} 末 40 行）：\n".implode("\n", $lines));
    }
}
