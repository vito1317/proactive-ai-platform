<?php

namespace Tests\Feature;

use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\CognitiveEngine;
use App\Pai\Cognition\RunStatus;
use App\Pai\Perception\PaiEvent;
use App\Pai\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResumableRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_replays_steps_and_only_calls_llm_for_continuation(): void
    {
        // 反思關閉 → 續跑只會有一次 LLM 呼叫（finish）
        $this->app->make(Settings::class)->set('react.reflect', false);

        // LLM 一律回 finish
        Http::fake(['*' => Http::response([
            'choices' => [['message' => [
                'content' => '{"thought":"完成","action":"finish","action_input":{"summary":"已續跑完成"}}',
            ], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 50],
        ])]);

        // 模擬一個「中斷」的運行：已完成 2 步（含一條發現），尚未 finish
        $event = PaiEvent::create([
            'source' => 'siem', 'topic' => 'siem.alert',
            'payload' => ['rule' => 'x'], 'intent' => 'security-alert',
            'severity' => 'high', 'domain' => 'sec-ir', 'status' => 'routed',
        ]);
        $run = AgentRun::create([
            'event_id' => $event->id,
            'domain' => 'sec-ir',
            'coordinator' => 'sec-ir-coordinator',
            'status' => RunStatus::Running,
            'tokens' => 100,
            'steps' => [
                ['step' => 1, 'thought' => 't1', 'action' => 'get_event_context', 'action_input' => [], 'observation' => 'ctx'],
                ['step' => 2, 'thought' => 't2', 'action' => 'record_finding', 'action_input' => ['finding' => '初步發現X'], 'observation' => '已記錄'],
            ],
        ]);

        $resumed = $this->app->make(CognitiveEngine::class)->resume($run);

        // 只為「續跑」呼叫一次 LLM——先前 2 步是重放，不重燒
        Http::assertSentCount(1);

        $this->assertSame(RunStatus::Completed, $resumed->status);
        $this->assertContains('初步發現X', $resumed->findings);          // 由重放 record_finding 重建
        $this->assertSame('已續跑完成', $resumed->summary);
        $this->assertGreaterThanOrEqual(150, $resumed->tokens);          // 100 既有 + 50 續跑
        $steps = $resumed->steps;
        $this->assertSame(1, $steps[0]['step']);                         // 原步驟保留
        $this->assertSame('finish', end($steps)['action']);              // 新增 finish 步
    }
}
