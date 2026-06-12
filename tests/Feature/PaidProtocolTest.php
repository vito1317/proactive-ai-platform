<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\RunStatus;
use App\Pai\Governance\ActionFeedback;
use App\Pai\Governance\PaidProtocolRecord;
use App\Pai\Perception\PaiEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaidProtocolTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(array $actions, string $severity = 'high'): AgentRun
    {
        $event = PaiEvent::create([
            'source' => 'log', 'topic' => 'log.error', 'severity' => $severity,
            'payload' => ['file' => 'demo.log'], 'status' => 'routed',
        ]);

        return AgentRun::create([
            'event_id' => $event->id, 'domain' => 'sec-ir', 'coordinator' => 'sec-ir-coordinator',
            'status' => RunStatus::AwaitingHitl, 'actions' => $actions, 'summary' => '測試摘要',
        ]);
    }

    public function test_record_has_six_layers_and_matches_framework_contract(): void
    {
        $run = $this->makeRun([
            ['action' => 'contain-host', 'rationale' => 'r', 'risk' => 'high',
                'confidence' => 0.9, 'granted_level' => 2, 'gate_reason' => '依自治規則', 'status' => 'awaiting_approval'],
        ]);

        $rec = PaidProtocolRecord::build($run);

        // PAID Protocol 必要鍵 + 向後相容鏡像（pai_protocol_version）——缺一不可（互通契約）
        foreach (['paid_protocol_version', 'pai_protocol_version', 'protocol', 'record_id', 'timestamp',
            '1_perception', '2_context', '3_anticipation', '4_execution', '5_delivery', '6_adaptation'] as $key) {
            $this->assertArrayHasKey($key, $rec);
        }
        $this->assertSame('PAID', $rec['protocol']);
        $this->assertSame('1.2', $rec['paid_protocol_version']);
        $this->assertSame($rec['paid_protocol_version'], $rec['pai_protocol_version']);  // 鏡像一致
        $this->assertStringStartsWith('paid_', $rec['record_id']);
        $this->assertSame('log', $rec['1_perception']['trigger_source']);
        $this->assertSame(0.8, $rec['3_anticipation']['urgency_score']);   // high → 0.8
        $this->assertSame('level_2_approval', $rec['5_delivery']['delivery_mode']);
        $this->assertTrue($rec['5_delivery']['requires_human_approval']);
        $this->assertSame('pending', $rec['6_adaptation']['user_feedback']);
    }

    public function test_record_file_written_and_updated_after_decision(): void
    {
        config(['pai.governance.records_path' => storage_path('app/test_paid_records')]);
        $this->actingAs(User::create(['name' => 'T', 'email' => 't@pai.test', 'password' => bcrypt('x')]));

        $run = $this->makeRun([
            ['action' => 'contain-host', 'rationale' => 'r', 'risk' => 'high',
                'confidence' => 0.9, 'granted_level' => 2, 'status' => 'awaiting_approval'],
        ]);

        $path = PaidProtocolRecord::write($run);
        $this->assertNotNull($path);
        $this->assertFileExists($path);

        // 人類駁回 → 紀錄的 6_adaptation 應更新、回饋入庫
        $this->post("/console/runs/{$run->id}/decision", ['index' => 0, 'decision' => 'reject'])
            ->assertRedirect();

        $rec = json_decode((string) file_get_contents($path), true);
        $this->assertSame('rejected', $rec['6_adaptation']['user_feedback']);
        $this->assertSame(1, ActionFeedback::where('action', 'contain-host')->where('positive', false)->count());

        array_map('unlink', glob(storage_path('app/test_paid_records/*.paid.json')) ?: []);
        @rmdir(storage_path('app/test_paid_records'));
    }

    public function test_suggested_and_observed_statuses_map_to_delivery_modes(): void
    {
        $run = $this->makeRun([
            ['action' => 'notify-team', 'rationale' => 'r', 'risk' => 'low',
                'confidence' => 0.6, 'granted_level' => 1, 'status' => 'suggested'],
        ], 'low');
        $run->status = RunStatus::Completed;

        $rec = PaidProtocolRecord::build($run);
        $this->assertSame('level_1_soft_nudge', $rec['5_delivery']['delivery_mode']);
        $this->assertFalse($rec['5_delivery']['requires_human_approval']);
        $this->assertSame('not_executed', $rec['4_execution']['status']);
    }
}
