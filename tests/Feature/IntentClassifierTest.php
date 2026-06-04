<?php

namespace Tests\Feature;

use App\Pai\Cognition\IntentClassifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntentClassifierTest extends TestCase
{
    private function fakeLlm(array $content): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($content)], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 10],
        ])]);
    }

    public function test_maps_message_to_domain_and_topic(): void
    {
        $this->fakeLlm(['domain' => 'sec-ir', 'topic' => 'siem.alert', 'severity' => 'high', 'rationale' => '勒索軟體']);

        $r = $this->app->make(IntentClassifier::class)->classify('host 中了勒索病毒');

        $this->assertSame('sec-ir', $r['domain']);
        $this->assertSame('siem.alert', $r['topic']);
        $this->assertSame('high', $r['severity']);
    }

    public function test_rejects_hallucinated_domain(): void
    {
        $this->fakeLlm(['domain' => 'totally-made-up', 'topic' => 'x.y', 'severity' => 'high', 'rationale' => 'nope']);

        $r = $this->app->make(IntentClassifier::class)->classify('隨便');

        $this->assertNull($r['domain']);
        $this->assertNull($r['topic']);
    }

    public function test_falls_back_to_first_topic_when_topic_invalid(): void
    {
        $this->fakeLlm(['domain' => 'dev-auto', 'topic' => 'bogus.topic', 'severity' => 'medium', 'rationale' => 'ci']);

        $r = $this->app->make(IntentClassifier::class)->classify('修一下測試');

        $this->assertSame('dev-auto', $r['domain']);
        $this->assertContains($r['topic'], ['git.push', 'pr.opened', 'ci.failed', 'issue.created']);
    }
}
