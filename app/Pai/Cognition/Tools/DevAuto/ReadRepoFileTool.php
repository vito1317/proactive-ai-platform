<?php

namespace App\Pai\Cognition\Tools\DevAuto;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/** 讀取目標 repo 內某檔案（唯讀，防路徑跳脫）。 */
final class ReadRepoFileTool implements Tool
{
    public function __construct(private readonly string $repoPath) {}

    public function name(): string
    {
        return 'read_repo_file';
    }

    public function description(): string
    {
        return '讀取 repo 內某檔案內容。action_input: {"path": "calculator.py"}。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $path = (string) ($input['path'] ?? '');
        if ($path === '') {
            return ToolResult::fail('需要 path。');
        }

        $base = realpath($this->repoPath);
        $target = realpath($this->repoPath.'/'.$path);

        // 防路徑跳脫：解析後必須仍在 repo 內
        if ($base === false || $target === false || ! str_starts_with($target, $base)) {
            return ToolResult::fail("路徑不存在或越界：{$path}");
        }
        if (! is_file($target)) {
            return ToolResult::fail("非檔案：{$path}");
        }

        $content = (string) file_get_contents($target);

        return ToolResult::ok("檔案 {$path}：\n".mb_substr($content, 0, 2000));
    }
}
