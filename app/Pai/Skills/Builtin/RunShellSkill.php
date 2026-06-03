<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Symfony\Component\Process\Process;
use Throwable;

/** 在伺服器執行一條 shell 指令並回傳輸出。高風險（需對話確認 / 開啟自我修改）。 */
class RunShellSkill implements Skill
{
    public function name(): string
    {
        return 'run-shell';
    }

    public function description(): string
    {
        return '在伺服器執行一條終端機指令並讀取輸出（以 www-data 權限執行，60 秒逾時）';
    }

    public function parameters(): array
    {
        return [
            'command' => '要執行的 shell 指令',
            'cwd' => '工作目錄（選填，預設專案根目錄）',
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
            return '請提供要執行的 command。';
        }
        $cwd = (string) ($args['cwd'] ?? base_path());
        if (! is_dir($cwd)) {
            $cwd = base_path();
        }

        $proc = Process::fromShellCommandline($cmd, $cwd, timeout: 60);
        try {
            $proc->run();
        } catch (Throwable $e) {
            return "執行失敗：{$e->getMessage()}";
        }

        $out = trim($proc->getOutput());
        $err = trim($proc->getErrorOutput());
        $text = "\$ {$cmd}\n結束碼：".$proc->getExitCode();
        if ($out !== '') {
            $text .= "\n[stdout]\n".mb_substr($out, 0, 3000);
        }
        if ($err !== '') {
            $text .= "\n[stderr]\n".mb_substr($err, 0, 1500);
        }

        return $text;
    }
}
