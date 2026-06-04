<?php

namespace Tests\Feature;

use App\Pai\Chat\CompactConversationJob;
use App\Pai\Chat\ContextCompactor;
use App\Pai\Chat\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContextCompactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_reply_over_threshold_queues_compaction(): void
    {
        Bus::fake([CompactConversationJob::class]);
        $conv = Conversation::create([]);
        for ($i = 0; $i < ContextCompactor::THRESHOLD; $i++) {
            $conv->messages()->create(['role' => 'user', 'content' => "msg {$i}", 'meta' => []]);
        }

        $conv->addMessage('assistant', '回覆');

        Bus::assertDispatched(CompactConversationJob::class, fn ($j) => $j->conversationId === $conv->id);
    }

    public function test_compact_summarizes_old_messages_and_keeps_recent(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '使用者在測試平台。'], 'finish_reason' => 'stop']], 'usage' => []])]);
        $conv = Conversation::create([]);
        for ($i = 0; $i < 30; $i++) {
            $conv->messages()->create(['role' => $i % 2 ? 'assistant' : 'user', 'content' => "msg {$i}", 'meta' => []]);
        }

        $this->app->make(ContextCompactor::class)->compact($conv);
        $conv->refresh();

        $this->assertSame('使用者在測試平台。', $conv->summary);
        // 只留最近 KEEP_RECENT 則為 active
        $this->assertSame(ContextCompactor::KEEP_RECENT, $conv->activeMessages()->count());
        // 原始訊息全數保留（後台仍可查看完整紀錄）
        $this->assertSame(31 - 1, $conv->messages()->count()); // 30 則
    }
}
