<?php

namespace Tests\Feature;

use App\Pai\Security\Sandbox;
use Tests\TestCase;

class SandboxTest extends TestCase
{
    private function sandbox(): Sandbox
    {
        return $this->app->make(Sandbox::class);
    }

    public function test_runs_python_and_captures_stdout(): void
    {
        $r = $this->sandbox()->run('python', 'print(2 + 3)');
        $this->assertTrue($r->ok);
        $this->assertSame('5', trim($r->stdout));
    }

    public function test_nonzero_exit_on_error(): void
    {
        $r = $this->sandbox()->run('python', 'raise SystemExit(7)');
        $this->assertFalse($r->ok);
        $this->assertSame(7, $r->exitCode);
    }

    public function test_long_running_code_is_killed_by_timeout(): void
    {
        $r = $this->sandbox()->run('python', 'import time; time.sleep(30)', 2);
        $this->assertTrue($r->timedOut, 'should be SIGKILLed at timeout');
        $this->assertFalse($r->ok);
    }

    public function test_network_is_blocked_under_bwrap(): void
    {
        $r = $this->sandbox()->run('bash-skip', 'x'); // 不支援語言 → none
        $this->assertSame('none', $r->isolation);

        // 僅在 bwrap 隔離下斷言無網路（弱隔離環境略過）
        $probe = $this->sandbox()->run('python',
            "import socket\n".
            "socket.setdefaulttimeout(3)\n".
            "try:\n".
            "    socket.create_connection(('8.8.8.8',53)); print('CONNECTED')\n".
            "except Exception as e:\n".
            "    print('BLOCKED')",
            8,
        );
        if ($probe->isolation === 'bwrap') {
            $this->assertStringContainsString('BLOCKED', $probe->stdout);
            $this->assertStringNotContainsString('CONNECTED', $probe->stdout);
        } else {
            $this->markTestSkipped('bwrap 不可用，跳過網路隔離斷言');
        }
    }
}
