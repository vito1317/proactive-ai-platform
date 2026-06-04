<?php

namespace Tests\Feature;

use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Perception\EventNormalizer;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use App\Pai\Perception\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EventIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 只攔截認知運行——路由仍會跑，但不觸發真實 LLM（測試不依賴 llama-server）
        Bus::fake([RunCoordinatorJob::class]);
    }

    public function test_webhook_ingests_normalizes_and_routes_security_event(): void
    {
        // queue=sync（測試環境）→ IngestEventJob 會即時跑完
        $res = $this->postJson('/webhooks/siem', [
            'topic' => 'siem.alert',
            'rule' => 'brute-force detected',
            'host' => '10.0.0.5',
        ]);

        $res->assertStatus(202)->assertJsonStructure(['event_id', 'status']);

        $event = PaiEvent::findOrFail($res->json('event_id'));
        $this->assertSame('siem', $event->source);
        $this->assertSame('security-alert', $event->intent);
        $this->assertSame(Severity::High, $event->severity);        // "brute-force" 命中高風險關鍵字
        $this->assertSame(EventStatus::Routed, $event->status);
        $this->assertSame('sec-ir', $event->domain);                // 由 sec-ir.yaml 訂閱 siem.alert
        $this->assertStringContainsString('sec-ir-coordinator', $event->note);
    }

    public function test_unsubscribed_topic_is_ignored(): void
    {
        $res = $this->postJson('/webhooks/misc', ['topic' => 'nobody.listens']);
        $res->assertStatus(202);

        $event = PaiEvent::findOrFail($res->json('event_id'));
        $this->assertSame(EventStatus::Ignored, $event->status);
        $this->assertNull($event->domain);
    }

    public function test_webhook_requires_topic(): void
    {
        $this->postJson('/webhooks/siem', ['foo' => 'bar'])->assertStatus(422);
    }

    public function test_devauto_ci_failure_routes_to_dev_auto(): void
    {
        $res = $this->postJson('/webhooks/ci', ['topic' => 'ci.failed', 'job' => 'phpunit']);
        $event = PaiEvent::findOrFail($res->json('event_id'));

        $this->assertSame('test-failure', $event->intent);
        $this->assertSame(Severity::Medium, $event->severity);      // "failed" 命中中風險關鍵字
        $this->assertSame('dev-auto', $event->domain);
    }

    public function test_normalizer_respects_explicit_payload_overrides(): void
    {
        $norm = (new EventNormalizer)->normalize('git.push', [
            'intent' => 'hotfix',
            'severity' => 'critical',
        ]);

        $this->assertSame('hotfix', $norm['intent']);
        $this->assertSame(Severity::Critical, $norm['severity']);
    }

    public function test_normalizer_fallback_intent_uses_last_topic_segment(): void
    {
        $norm = (new EventNormalizer)->normalize('vendor.weird.thing', []);
        $this->assertSame('thing', $norm['intent']);
        $this->assertSame(Severity::Low, $norm['severity']);
    }
}
