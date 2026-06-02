<?php

namespace Tests\Feature;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tools\DevAuto\ProposePatchTool;
use App\Pai\Cognition\Tools\DevAuto\ReadRepoFileTool;
use App\Pai\Cognition\Tools\DevAuto\RunTestsTool;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Perception\PaiEvent;
use App\Pai\Security\Sandbox;
use Tests\TestCase;

class DevAutoToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 還原示範 repo 的「故意 bug」狀態（避免被閉環執行的 live demo 改掉而影響測試）
        file_put_contents(
            config('pai.devauto.repo_path').'/calculator.py',
            "\"\"\"簡單計算器模組（示範 DevAuto 目標 repo）。\"\"\"\n\n\ndef add(a, b):\n    # BUG: 應為加法，誤寫成減法\n    return a - b\n\n\ndef multiply(a, b):\n    return a * b\n",
        );
    }

    private function ctx(): AgentContext
    {
        $pack = $this->app->make(DomainRegistry::class)->get('dev-auto');
        $event = new PaiEvent(['source' => 'ci', 'topic' => 'ci.failed', 'payload' => []]);

        return new AgentContext($event, $pack);
    }

    public function test_run_tests_reports_real_failure_in_sandbox(): void
    {
        $tool = new RunTestsTool(
            $this->app->make(Sandbox::class),
            config('pai.devauto.repo_path'),
            config('pai.devauto.test_entry'),
        );

        $r = $tool->run([], $this->ctx());

        $this->assertTrue($r->ok);
        $this->assertStringContainsString('測試失敗', $r->observation);
        $this->assertStringContainsString('add(2', $r->observation); // 斷言訊息來自 repo 測試
    }

    public function test_read_repo_file(): void
    {
        $tool = new ReadRepoFileTool(config('pai.devauto.repo_path'));
        $r = $tool->run(['path' => 'calculator.py'], $this->ctx());

        $this->assertTrue($r->ok);
        $this->assertStringContainsString('def add', $r->observation);
    }

    public function test_read_repo_file_blocks_path_traversal(): void
    {
        $tool = new ReadRepoFileTool(config('pai.devauto.repo_path'));
        $r = $tool->run(['path' => '../../../../etc/passwd'], $this->ctx());

        $this->assertFalse($r->ok);
        $this->assertStringContainsString('越界', $r->observation);
    }

    public function test_propose_patch_records_high_risk_action(): void
    {
        $ctx = $this->ctx();
        $tool = new ProposePatchTool;
        $r = $tool->run(['path' => 'calculator.py', 'summary' => '修正 add', 'patch' => 'return a + b'], $ctx);

        $this->assertTrue($r->ok);
        $this->assertCount(1, $ctx->actions);
        $this->assertSame('apply-patch:calculator.py', $ctx->actions[0]['action']);
        $this->assertSame('high', $ctx->actions[0]['risk']);
        $this->assertNotEmpty($ctx->findings);
    }
}
