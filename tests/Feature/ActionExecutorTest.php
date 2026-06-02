<?php

namespace Tests\Feature;

use App\Pai\Action\ActionExecutor;
use App\Pai\Security\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActionExecutorTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpRepo;

    protected function setUp(): void
    {
        parent::setUp();
        // 隔離用的目標 repo（放專案內，避免 bwrap 的 --tmpfs /tmp 遮蔽；不動正式示範 repo）
        $this->tmpRepo = storage_path('app/test-exec-'.uniqid());
        mkdir($this->tmpRepo);
        file_put_contents($this->tmpRepo.'/calculator.py', "def add(a, b):\n    return a - b\n");
        file_put_contents($this->tmpRepo.'/run_tests.py',
            "from calculator import add\nassert add(2, 3) == 5, f\"got {add(2,3)}\"\nprint('OK')\n");
        config(['pai.devauto.repo_path' => $this->tmpRepo, 'pai.devauto.test_entry' => 'run_tests.py']);
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->tmpRepo.'/*'));
        @rmdir($this->tmpRepo);
        parent::tearDown();
    }

    public function test_apply_patch_writes_file_and_reruns_tests_green(): void
    {
        $action = [
            'action' => 'apply-patch:calculator.py',
            'payload' => ['path' => 'calculator.py', 'patch' => "def add(a, b):\n    return a + b\n"],
        ];

        $r = $this->app->make(ActionExecutor::class)->execute($action, 'dev-auto');

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('全綠', $r['output']);
        $this->assertStringContainsString('return a + b', file_get_contents($this->tmpRepo.'/calculator.py'));
    }

    public function test_apply_patch_rejects_path_traversal(): void
    {
        $r = $this->app->make(ActionExecutor::class)->execute([
            'action' => 'apply-patch:x',
            'payload' => ['path' => '../../../etc/passwd', 'patch' => 'x'],
        ], 'dev-auto');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('越界', $r['output']);
    }

    public function test_containment_simulated_without_endpoint(): void
    {
        config(['pai.secir.containment_url' => null]);
        $r = $this->app->make(ActionExecutor::class)->execute([
            'action' => 'isolate-host', 'payload' => ['target' => 'host-7'],
        ], 'sec-ir');

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('模擬', $r['output']);
        $this->assertStringContainsString('host-7', $r['output']);
    }

    public function test_clear_cache_remediation_runs(): void
    {
        $r = $this->app->make(ActionExecutor::class)->execute(['action' => 'clear-cache', 'payload' => []], 'log-ops');
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('快取', $r['output']);
    }

    public function test_containment_injects_credential_when_endpoint_set(): void
    {
        config(['pai.secir.containment_url' => 'https://edr.example/contain']);
        $this->app->make(SecretVault::class)->put('edr_token', 'EDR-KEY');
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->app->make(ActionExecutor::class)->execute([
            'action' => 'firewall.block', 'payload' => ['target' => '185.0.0.7'],
        ], 'sec-ir');

        Http::assertSent(fn ($req) => $req->header('Authorization')[0] === 'Bearer EDR-KEY'
            && str_contains($req->body(), '185.0.0.7'));
    }
}
