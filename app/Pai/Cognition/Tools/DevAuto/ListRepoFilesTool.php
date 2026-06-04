<?php

namespace App\Pai\Cognition\Tools\DevAuto;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/** 列出目標 repo 的檔案（唯讀）。 */
final class ListRepoFilesTool implements Tool
{
    public function __construct(private readonly string $repoPath) {}

    public function name(): string
    {
        return 'list_repo_files';
    }

    public function description(): string
    {
        return '列出目標 repo 的檔案清單。無需參數。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        if (! is_dir($this->repoPath)) {
            return ToolResult::fail('找不到 repo：'.$this->repoPath);
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repoPath, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            $rel = ltrim(str_replace($this->repoPath, '', $file->getPathname()), '/');
            if (! str_contains($rel, '/.')) {
                $files[] = $rel;
            }
        }
        sort($files);

        return ToolResult::ok("repo 檔案：\n".implode("\n", $files));
    }
}
