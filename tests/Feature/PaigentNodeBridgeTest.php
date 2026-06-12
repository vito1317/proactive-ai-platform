<?php

namespace Tests\Feature;

use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Perception\PaiEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** paigent（PyPI 套件）節點哨兵 → 平台事件入口的橋接：兩種原生格式都要能直接打進來。 */
class PaigentNodeBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RunCoordinatorJob::class]);
    }

    public function test_paigent_webhook_notifier_payload_is_accepted(): void
    {
        // paigent WebhookNotifier 的實際輸出格式（actions.py）
        $res = $this->postJson('/webhooks/mac-node', [
            'title' => '建議：cleanup',
            'body' => 'CPU 使用率超過閾值，建議清理背景程序',
            'intent' => [
                'action' => 'cleanup', 'params' => [],
                'confidence' => 0.9, 'urgency' => 0.95,
                'rationale' => 'CPU 超標', 'requested_level' => 2, 'event_id' => 'abc123',
            ],
        ]);

        $res->assertStatus(202);
        $event = PaiEvent::findOrFail($res->json('event_id'));
        $this->assertSame('mac-node', $event->source);
        $this->assertSame('node.intent.cleanup', $event->topic);
        $this->assertSame(0.95, $event->payload['intent']['urgency']);
    }

    public function test_suggest_wrapper_intent_unwraps_to_semantic_topic(): void
    {
        // paigent SUGGEST 流程：原始意圖被包進 __notify__ wrapper 的 params.intent
        $res = $this->postJson('/webhooks/mac-node', [
            'title' => '建議：cpu-overload',
            'body' => '節點 mac CPU load 9.5 超過閾值 3.0',
            'intent' => [
                'action' => '__notify__',
                'params' => ['title' => '建議：cpu-overload', 'body' => '…',
                    'intent' => ['action' => 'cpu-overload', 'confidence' => 0.9, 'urgency' => 0.9]],
                'confidence' => 0.9, 'urgency' => 0.9,
            ],
        ]);

        $res->assertStatus(202);
        $this->assertSame('node.intent.cpu-overload', PaiEvent::findOrFail($res->json('event_id'))->topic);
    }

    public function test_pai_protocol_record_is_accepted(): void
    {
        // pai-framework build_record() 的 v1.1 紀錄（6 層）
        $res = $this->postJson('/webhooks/edge-sentinel', [
            'pai_protocol_version' => '1.1',
            'record_id' => 'pai_20260612_a1b2c3',
            'timestamp' => '2026-06-12T00:00:00Z',
            '1_perception' => ['trigger_source' => 'cpu-monitor', 'event_type' => 'metric.breach', 'raw_data_summary' => ['cpu' => 91]],
            '2_context' => ['user_current_state' => 'unknown', 'relevant_memory' => [], 'action_history' => []],
            '3_anticipation' => ['predicted_intent' => 'CPU 超標', 'urgency_score' => 0.95, 'confidence_score' => 0.9],
            '4_execution' => ['actions_taken' => [], 'status' => 'not_executed'],
            '5_delivery' => ['delivery_mode' => 'level_2_approval', 'requires_human_approval' => true],
            '6_adaptation' => ['user_feedback' => 'pending', 'learning_adjustment' => null],
        ]);

        $res->assertStatus(202);
        $event = PaiEvent::findOrFail($res->json('event_id'));
        $this->assertSame('metric.breach', $event->topic);
        $this->assertSame('1.1', $event->payload['pai_protocol_version']);
    }

    public function test_standard_payload_unchanged_and_missing_topic_still_422(): void
    {
        $this->postJson('/webhooks/siem', ['topic' => 'siem.alert', 'rule' => 'x'])->assertStatus(202);
        $this->postJson('/webhooks/siem', ['rule' => '沒有 topic 也非 paigent 格式'])->assertStatus(422);
    }
}
