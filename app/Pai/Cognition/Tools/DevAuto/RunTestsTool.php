<?php

namespace App\Pai\Cognition\Tools\DevAuto;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;
use App\Pai\Security\Sandbox;

/**
 * 在隔離沙盒 (bwrap：唯讀根、無網路) 內跑目標 repo 的測試。
 * 串起 P2 沙盒——AI 可實際驗證程式碼，而不會危害主機。
 */
final class RunTestsTool implements Tool
{
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly string $repoPath,
        private readonly string $testEntry,
    ) {}

    public function name(): string
    {
        return 'run_tests';
    }

    public function description(): string
    {
        return '在隔離沙盒內執行目標 repo 的測試，回傳通過/失敗與輸出。無需參數。修補後應再跑一次確認。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $repo = var_export($this->repoPath, true);
        $entry = var_export($this->testEntry, true);

        // 在沙盒中以 subprocess 跑 repo 測試入口，回傳其 stdout/stderr 與 exit code
        $harness = <<<PY
        import subprocess, sys
        p = subprocess.run([sys.executable, {$entry}], cwd={$repo}, capture_output=True, text=True)
        sys.stdout.write(p.stdout)
        sys.stderr.write(p.stderr)
        sys.exit(p.returncode)
        PY;

        $res = $this->sandbox->run('python', $harness, 30);

        if ($res->timedOut) {
            return ToolResult::fail('測試逾時被中止。');
        }

        $passed = $res->exitCode === 0;
        $output = trim($res->stdout."\n".$res->stderr);
        $label = $passed ? '✅ 測試通過' : "❌ 測試失敗 (exit {$res->exitCode})";

        return ToolResult::ok("{$label} [isolation={$res->isolation}]\n".mb_substr($output, 0, 800));
    }
}
