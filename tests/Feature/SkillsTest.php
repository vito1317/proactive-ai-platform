<?php

namespace Tests\Feature;

use App\Pai\Chat\Conversation;
use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\RunStatus;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use App\Pai\Settings\Settings;
use App\Pai\Skills\SkillRegistry;
use App\Pai\Skills\SkillRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SkillsTest extends TestCase
{
    use RefreshDatabase;

    /** 一個 decide() 回應（多輪代理每輪輸出 {action,args,final}）。 */
    private function decide(array $obj): array
    {
        return ['choices' => [['message' => ['content' => json_encode($obj, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']], 'usage' => []];
    }

    private function runner(): SkillRunner
    {
        return $this->app->make(SkillRunner::class);
    }

    public function test_low_risk_skill_runs_then_finishes(): void
    {
        // 第1輪：用 list-domains；第2輪：finish 並給最終回覆
        Http::fakeSequence()
            ->push($this->decide(['action' => 'list-domains', 'args' => []]))
            ->push($this->decide(['action' => 'finish', 'final' => '目前有這些領域包。']));
        $conv = Conversation::create([]);

        $r = $this->runner()->handle($conv, '列出領域包');
        $this->assertSame('skill', $r['meta']['category']);
        $this->assertStringContainsString('領域包', $r['reply']);
        $this->assertNull($conv->fresh()->pending_skill);
    }

    public function test_high_risk_requires_confirmation_then_continues(): void
    {
        Http::fakeSequence()
            ->push($this->decide(['action' => 'update-setting', 'args' => ['key' => 'llm.temperature', 'value' => '0.5']]))
            ->push($this->decide(['action' => 'finish', 'final' => '已更新溫度。']));
        $conv = Conversation::create([]);
        $runner = $this->runner();

        $r = $runner->handle($conv, '把溫度改成 0.5');
        $this->assertStringContainsString('高風險', $r['reply']);
        $this->assertSame('update-setting', $conv->fresh()->pending_skill['skill']);

        $resolved = $runner->resolvePending($conv->fresh(), '確認');
        $this->assertNotNull($resolved);
        $this->assertNull($conv->fresh()->pending_skill);
        $this->assertSame(0.5, $this->app->make(Settings::class)->get('llm.temperature'));
    }

    public function test_high_risk_cancelled_by_conversation(): void
    {
        Http::fakeSequence()->push($this->decide(['action' => 'restart-workers', 'args' => []]));
        $conv = Conversation::create([]);
        $runner = $this->runner();
        $runner->handle($conv, '重啟 worker');

        $resolved = $runner->resolvePending($conv->fresh(), '取消');
        $this->assertStringContainsString('已取消', $resolved['reply']);
        $this->assertNull($conv->fresh()->pending_skill);
    }

    public function test_global_allow_skips_confirmation(): void
    {
        $this->app->make(Settings::class)->set('skills.allow_system_writes', true);
        Http::fakeSequence()
            ->push($this->decide(['action' => 'update-setting', 'args' => ['key' => 'react.max_steps', 'value' => '8']]))
            ->push($this->decide(['action' => 'finish', 'final' => '已設定步數。']));
        $conv = Conversation::create([]);

        $this->runner()->handle($conv, '把步數設成 8');
        $this->assertSame(8, $this->app->make(Settings::class)->get('react.max_steps'));
    }

    public function test_stop_task_skill_cancels_running_run(): void
    {
        $this->app->make(Settings::class)->set('skills.allow_system_writes', true);
        $event = PaiEvent::create(['source' => 'chat', 'topic' => 't', 'status' => EventStatus::Routed, 'payload' => []]);
        $run = AgentRun::create(['event_id' => $event->id, 'domain' => 'sec-ir', 'coordinator' => 'sec-ir', 'status' => RunStatus::Running, 'steps' => [], 'findings' => [], 'actions' => []]);
        Http::fakeSequence()
            ->push($this->decide(['action' => 'stop-task', 'args' => []]))
            ->push($this->decide(['action' => 'finish', 'final' => '已中止任務。']));
        $conv = Conversation::create([]);

        $this->runner()->handle($conv, '停止任務');
        $this->assertSame(RunStatus::Cancelled, $run->fresh()->status);
    }

    public function test_registry_has_builtin_skills(): void
    {
        $names = array_keys($this->app->make(SkillRegistry::class)->all());
        foreach (['get-settings', 'update-setting', 'toggle-domain', 'restart-workers', 'stop-task', 'tail-logs', 'list-domains',
            'run-shell', 'open-app', 'read-file', 'write-file', 'edit-file', 'insert-in-file', 'answer-from-web',
            'web-search', 'web-fetch', 'add-mcp-server', 'generate-install-command'] as $n) {
            $this->assertContains($n, $names);
        }
    }

    public function test_edit_file_replaces_unique_string(): void
    {
        $path = storage_path('app/edit-test.txt');
        file_put_contents($path, "line one\nTARGET here\nline three");
        $this->app->make(Settings::class)->set('skills.allow_system_writes', true);
        Http::fakeSequence()
            ->push($this->decide(['action' => 'edit-file', 'args' => ['path' => $path, 'old' => 'TARGET here', 'new' => 'REPLACED']]))
            ->push($this->decide(['action' => 'finish', 'final' => '已取代。']));
        $conv = Conversation::create([]);

        $this->runner()->handle($conv, '改檔');
        $this->assertStringContainsString('REPLACED', file_get_contents($path));
        @unlink($path);
    }

    public function test_write_file_requires_confirmation(): void
    {
        $path = storage_path('app/skill-write-test.txt');
        @unlink($path);
        Http::fakeSequence()
            ->push($this->decide(['action' => 'write-file', 'args' => ['path' => $path, 'content' => 'written-by-skill']]))
            ->push($this->decide(['action' => 'finish', 'final' => '已寫入。']));
        $conv = Conversation::create([]);
        $runner = $this->runner();

        $runner->handle($conv, '寫檔');
        $this->assertSame('write-file', $conv->fresh()->pending_skill['skill']);
        $this->assertFileDoesNotExist($path); // 未確認前不執行

        $runner->resolvePending($conv->fresh(), '確認');
        $this->assertStringEqualsFile($path, 'written-by-skill');
        @unlink($path);
    }

    public function test_reply_always_allow_enables_and_executes(): void
    {
        Http::fakeSequence()
            ->push($this->decide(['action' => 'restart-workers', 'args' => []]))
            ->push($this->decide(['action' => 'finish', 'final' => '已重啟。']));
        $conv = Conversation::create([]);
        $runner = $this->runner();
        $runner->handle($conv, '重啟 worker');

        $resolved = $runner->resolvePending($conv->fresh(), '一律允許');
        $this->assertStringContainsString('一律允許', $resolved['reply']);
        $this->assertTrue($conv->fresh()->always_allow_skills);
        $this->assertTrue($runner->writesAllowed($conv->fresh()));
    }

    public function test_always_allow_command_toggles_flag(): void
    {
        $conv = Conversation::create([]);
        $runner = $this->runner();

        $r = $runner->handle($conv, '一律允許高風險操作');
        $this->assertTrue($conv->fresh()->always_allow_skills);
        $this->assertTrue($r['meta']['always_allow']);

        $runner->handle($conv->fresh(), '取消一律允許');
        $this->assertFalse($conv->fresh()->always_allow_skills);
    }

    public function test_run_shell_executes_then_ai_interprets_output(): void
    {
        $this->app->make(Settings::class)->set('skills.allow_system_writes', true);
        // 1) decide 用 run-shell  2) decide finish（其 prompt 會帶入指令輸出）
        Http::fakeSequence()
            ->push($this->decide(['action' => 'run-shell', 'args' => ['command' => 'echo pai-shell-ok']]))
            ->push($this->decide(['action' => 'finish', 'final' => '指令已執行，輸出 pai-shell-ok']));
        $conv = Conversation::create([]);

        $r = $this->runner()->handle($conv, '跑 echo');
        $this->assertStringContainsString('指令已執行', $r['reply']);
        // 證明指令真的有跑：echo 輸出被帶進下一輪 decide 的 prompt
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'pai-shell-ok'));
    }

    public function test_multi_round_runs_two_commands(): void
    {
        $this->app->make(Settings::class)->set('skills.allow_system_writes', true);
        // 連續兩步：先看磁碟 → 再清理 → finish（模擬「磁碟滿了自動清理」）
        Http::fakeSequence()
            ->push($this->decide(['action' => 'run-shell', 'args' => ['command' => 'echo STEP1-DISK']]))
            ->push($this->decide(['action' => 'run-shell', 'args' => ['command' => 'echo STEP2-CLEAN']]))
            ->push($this->decide(['action' => 'finish', 'final' => '已查看磁碟並清理完成。']));
        $conv = Conversation::create([]);

        $r = $this->runner()->handle($conv, '磁碟滿了幫我清理');
        $this->assertSame(2, $r['meta']['rounds']);
        $this->assertStringContainsString('清理', $r['reply']);
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'STEP1-DISK'));
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'STEP2-CLEAN'));
    }
}
