<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\RunStatus;
use App\Pai\Perception\PaiEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HitlDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'T', 'email' => 't@pai.test', 'password' => bcrypt('x')]));
    }

    private function makeRun(array $actions): AgentRun
    {
        $event = PaiEvent::create(['source' => 'console', 'topic' => 'siem.alert', 'payload' => [], 'status' => 'routed']);

        return AgentRun::create([
            'event_id' => $event->id,
            'domain' => 'sec-ir',
            'coordinator' => 'sec-ir-coordinator',
            'status' => RunStatus::AwaitingHitl,
            'actions' => $actions,
        ]);
    }

    public function test_approve_executes_action_and_completes_run(): void
    {
        $run = $this->makeRun([
            ['action' => 'isolate-host', 'rationale' => 'r', 'risk' => 'high', 'status' => 'awaiting_approval'],
        ]);

        $this->post("/console/runs/{$run->id}/decision", ['index' => 0, 'decision' => 'approve'])
            ->assertRedirect();

        $run->refresh();
        $this->assertSame('executed', $run->actions[0]['status']);
        $this->assertSame(RunStatus::Completed, $run->status);
    }

    public function test_reject_marks_action_rejected(): void
    {
        $run = $this->makeRun([
            ['action' => 'firewall.block', 'rationale' => 'r', 'risk' => 'high', 'status' => 'awaiting_approval'],
        ]);

        $this->post("/console/runs/{$run->id}/decision", ['index' => 0, 'decision' => 'reject'])
            ->assertRedirect();

        $run->refresh();
        $this->assertSame('rejected', $run->actions[0]['status']);
        $this->assertSame(RunStatus::Completed, $run->status);
    }

    public function test_partial_approval_keeps_run_awaiting(): void
    {
        $run = $this->makeRun([
            ['action' => 'isolate-host', 'rationale' => 'r', 'risk' => 'high', 'status' => 'awaiting_approval'],
            ['action' => 'firewall.block', 'rationale' => 'r', 'risk' => 'high', 'status' => 'awaiting_approval'],
        ]);

        $this->post("/console/runs/{$run->id}/decision", ['index' => 0, 'decision' => 'approve']);

        $run->refresh();
        $this->assertSame('executed', $run->actions[0]['status']);
        $this->assertSame('awaiting_approval', $run->actions[1]['status']);
        $this->assertSame(RunStatus::AwaitingHitl, $run->status);
    }
}
