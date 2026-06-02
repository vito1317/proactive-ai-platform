<?php

namespace Tests\Feature;

use App\Pai\Cognition\CognitiveEngine;
use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Perception\PaiEvent;
use App\Pai\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class A2AHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function llmStep(array $decision): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode($decision, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 10],
        ];
    }

    public function test_secir_hands_off_to_devauto(): void
    {
        Bus::fake([RunCoordinatorJob::class]);                 // 不實跑子協調者
        $this->app->make(Settings::class)->set('react.reflect', false);

        // 第 1 步交辦、第 2 步 finish
        Http::fakeSequence()
            ->push($this->llmStep([
                'thought' => '這是程式漏洞，交給開發領域',
                'action' => 'handoff_to_domain',
                'action_input' => ['to_domain' => 'dev-auto', 'task' => '修補 CVE-2026-0001', 'artifact' => ['repo' => 'svc', 'cve' => 'CVE-2026-0001']],
            ]))
            ->push($this->llmStep(['thought' => '完成', 'action' => 'finish', 'action_input' => ['summary' => '已交辦修補']]))
            ->whenEmpty(Http::response($this->llmStep(['action' => 'finish', 'action_input' => ['summary' => 'x']])));

        $event = PaiEvent::create([
            'source' => 'siem', 'topic' => 'siem.alert', 'payload' => ['cve' => 'CVE-2026-0001'],
            'intent' => 'security-alert', 'severity' => 'high', 'domain' => 'sec-ir', 'status' => 'routed',
        ]);
        $pack = $this->app->make(DomainRegistry::class)->get('sec-ir');

        $run = $this->app->make(CognitiveEngine::class)->run($event, $pack);

        // 建立了交給 dev-auto 的 A2A 子事件
        $child = PaiEvent::where('source', 'a2a')->where('domain', 'dev-auto')->first();
        $this->assertNotNull($child);
        $this->assertSame($event->id, $child->payload['parent_event_id']);
        $this->assertSame('修補 CVE-2026-0001', $child->payload['task']);
        $this->assertSame('CVE-2026-0001', $child->payload['artifact']['cve']);

        // 已派發子協調者
        Bus::assertDispatched(RunCoordinatorJob::class, fn ($j) => $j->eventId === $child->id && $j->domain === 'dev-auto');

        // 原運行記錄了交辦
        $this->assertTrue(collect($run->findings)->contains(fn ($f) => str_contains($f, '已交辦')));
    }

    public function test_handoff_is_not_duplicated_on_resume(): void
    {
        Bus::fake([RunCoordinatorJob::class]);
        $this->app->make(Settings::class)->set('react.reflect', false);
        Http::fake(['*' => Http::response($this->llmStep(['action' => 'finish', 'action_input' => ['summary' => 'done']]))]);

        $event = PaiEvent::create([
            'source' => 'siem', 'topic' => 'siem.alert', 'payload' => [],
            'intent' => 'security-alert', 'severity' => 'high', 'domain' => 'sec-ir', 'status' => 'routed',
        ]);
        $pack = $this->app->make(DomainRegistry::class)->get('sec-ir');

        // 預置一個已含 handoff 步驟的「中斷」運行
        $run = \App\Pai\Cognition\AgentRun::create([
            'event_id' => $event->id, 'domain' => 'sec-ir', 'coordinator' => 'sec-ir-coordinator',
            'status' => \App\Pai\Cognition\RunStatus::Running,
            'steps' => [
                ['step' => 1, 'thought' => 't', 'action' => 'handoff_to_domain',
                    'action_input' => ['to_domain' => 'dev-auto', 'task' => 'T1', 'artifact' => []], 'observation' => 'ok'],
            ],
        ]);

        $this->app->make(CognitiveEngine::class)->resume($run);   // 第一次續跑 → 派發
        $this->app->make(CognitiveEngine::class)->resume($run->fresh()); // 再續跑 → 不應重複

        $this->assertSame(1, PaiEvent::where('source', 'a2a')->where('domain', 'dev-auto')->count());
    }
}
