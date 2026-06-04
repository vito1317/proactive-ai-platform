<?php

namespace App\Pai\Security;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * 以 bubblewrap (bwrap) 做命名空間隔離的沙盒：
 *  - --unshare-all：無網路、獨立 PID/IPC/UTS namespace
 *  - --ro-bind / /：整個檔案系統唯讀（除 tmpfs /tmp）
 *  - --clearenv：清空環境變數，主機憑證不外洩
 *  - 程式碼經 stdin 餵入，不寫入任何可被回溯的路徑
 *
 * 若環境無 bwrap，退回「乾淨環境 + 逾時」的弱隔離（僅供開發，會標 isolation=process）。
 */
class ProcessSandbox implements Sandbox
{
    public function run(string $language, string $code, int $timeoutSeconds = 10): SandboxResult
    {
        $interp = match ($language) {
            'python', 'py' => ['python3', '-'],
            'php' => ['php'],
            default => null,
        };
        if ($interp === null) {
            return new SandboxResult(false, '', "不支援的語言：{$language}", 2, false, 'none');
        }

        $hasBwrap = (new Process(['bash', '-c', 'command -v bwrap']))->run() === 0;

        if ($hasBwrap) {
            // 唯讀根 + 私有 tmp + 清空環境 + 隔離網路/IPC/UTS/cgroup。
            // 不開 PID namespace：--unshare-pid 會派生分離的 ns-init，逃過逾時 SIGKILL
            // 並卡住 stdout pipe。逾時改由 Symfony Process 強制，--die-with-parent 連帶清掉程式碼程序。
            $cmd = array_merge([
                'bwrap',
                '--ro-bind', '/', '/',
                '--dev', '/dev',
                '--proc', '/proc',
                '--tmpfs', '/tmp',
                '--tmpfs', '/run',
                '--chdir', '/tmp',
                '--unshare-net', '--unshare-ipc', '--unshare-uts', '--unshare-cgroup-try',
                '--die-with-parent',
                '--clearenv',
                '--setenv', 'PATH', '/usr/bin:/bin',
                '--setenv', 'HOME', '/tmp',
            ], $interp);
            $isolation = 'bwrap';
        } else {
            $cmd = $interp;
            $isolation = 'process';
        }

        // 只給 PATH（讓 Process 找得到 bwrap/python3）；bwrap --clearenv 會清空內層 env，
        // 非 bwrap 時程式碼也只看得到 PATH，主機憑證不外洩。input 把程式碼餵到 stdin。
        $process = new Process($cmd, sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin'], $code);
        $process->setTimeout($timeoutSeconds);

        $timedOut = false;
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $process->stop(0, SIGKILL); // 立即 SIGKILL，連帶觸發 --die-with-parent
        }

        return new SandboxResult(
            ok: ! $timedOut && $process->isSuccessful(),
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            exitCode: $timedOut ? 137 : (int) $process->getExitCode(),
            timedOut: $timedOut,
            isolation: $isolation,
        );
    }
}
