<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Symfony\Component\Process\Process;
use Throwable;

/** 在背景啟動一個程式 / 長駐指令（detached）。高風險。 */
class OpenAppSkill implements Skill
{
    public function name(): string
    {
        return 'open-app';
    }

    public function description(): string
    {
        return '在背景啟動一個程式或長駐指令（detached，不等待結束），例如啟動服務、開啟工具';
    }

    public function parameters(): array
    {
        return [
            'command' => '要啟動的程式或指令（會以背景方式執行）',
            'cwd' => '工作目錄（選填）',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $cmd = trim((string) ($args['command'] ?? ''));
        if ($cmd === '') {
            return '請提供要啟動的 command。';
        }
        $cwd = (string) ($args['cwd'] ?? base_path());
        if (! is_dir($cwd)) {
            $cwd = base_path();
        }

        try {
            // 完全脫離父程序（setsid + nohup），父程序結束也不影響
            $proc = Process::fromShellCommandline("setsid nohup {$cmd} >/dev/null 2>&1 &", $cwd, timeout: 10);
            $proc->disableOutput();
            $proc->run();
        } catch (Throwable $e) {
            return "啟動失敗：{$e->getMessage()}";
        }

        return "已在背景啟動：{$cmd} 🚀（detached）。可用 run-shell 配合 pgrep/ps 確認是否在執行。";
    }
}
