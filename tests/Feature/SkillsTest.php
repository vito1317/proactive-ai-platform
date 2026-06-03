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

    private function fakePick(string $skill, array $args = []): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['skill' => $skill, 'args' => $args])], 'finish_reason' => 'stop']],
            'usage' => [],
        ])]);
    }

    public function test_low_risk_skill_runs_immediately(): void
    {
        $this->fakePick('list-domains');
        $conv = Conversation::create([]);
        $r = $this->app->make(SkillRunner::class)->handle($conv, '列出領域包');

        $this->assertSame('skill', $r['meta']['category']);
        $this->assertSame('list-domains', $r['meta']['skill']);
        $this->assertNull($conv->fresh()->pending_skill);
    }

    public function test_high_risk_skill_requires_conversational_confirmation(): void
    {
        $this->fakePick('update-setting', ['key' => 'llm.temperature', 'value' => '0.5']);
        $conv = Conversation::create([]);
        $runner = $this->app->make(SkillRunner::class);

        $r = $runner->handle($conv, '把溫度改成 0.5');
        $this->assertStringContainsString('高風險', $r['reply']);
        $this->assertSame('update-setting', $conv->fresh()->pending_skill['skill']);

        // 使用者回「確認」→ 執行
        $resolved = $runner->resolvePending($conv->fresh(), '確認');
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('已更新設定', $resolved['reply']);
        $this->assertNull($conv->fresh()->pending_skill);
        $this->assertSame(0.5, $this->app->make(Settings::class)->get('llm.temperature'));
    }

    public function test_high_risk_skill_cancelled_by_conversation(): void
    {
        $this->fakePick('restart-workers');
        $conv = Conversation::create([]);
        $runner = $this->app->make(SkillRunner::class);
        $runner->handle($conv, '重啟 worker');

        $resolved = $runner->resolvePending($conv->fresh(), '取消');
        $this->assertStringContainsString('已取消', $resolved['reply']);
        $this->assertNull($conv->fresh()->pending_skill);
    }

    public function test_global_allow_skips_confirmation(): void
    {
        $this->app->make(Settings::class)->set('skills.allow_system_writes', true);
        $this->fakePick('update-setting', ['key' => 'react.max_steps', 'value' => '8']);
        $conv = Conversation::create([]);

        $r = $this->app->make(SkillRunner::class)->handle($conv, '把步數設成 8');
        $this->assertStringContainsString('已更新設定', $r['reply']);
        $this->assertSame(8, $this->app->make(Settings::class)->get('react.max_steps'));
    }

    public function test_stop_task_skill_cancels_running_run(): void
    {
        $this->app->make(Settings::class)->set('skills.allow_system_writes', true);
        $event = PaiEvent::create([
            'source' => 'chat', 'topic' => 't', 'status' => EventStatus::Routed, 'payload' => [],
        ]);
        $run = AgentRun::create(['event_id' => $event->id, 'domain' => 'sec-ir', 'coordinator' => 'sec-ir', 'status' => RunStatus::Running, 'steps' => [], 'findings' => [], 'actions' => []]);
        $this->fakePick('stop-task', []);
        $conv = Conversation::create([]);

        $r = $this->app->make(SkillRunner::class)->handle($conv, '停止任務');
        $this->assertStringContainsString('已中止', $r['reply']);
        $this->assertSame(RunStatus::Cancelled, $run->fresh()->status);
    }

    public function test_registry_has_builtin_skills(): void
    {
        $names = array_keys($this->app->make(SkillRegistry::class)->all());
        foreach (['get-settings', 'update-setting', 'toggle-domain', 'restart-workers', 'stop-task', 'tail-logs', 'list-domains'] as $n) {
            $this->assertContains($n, $names);
        }
    }
}
