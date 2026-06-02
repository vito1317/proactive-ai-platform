<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pai\Chat\Conversation;
use App\Pai\Cognition\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatStreamTest extends TestCase
{
    use RefreshDatabase;

    private function sseBody(array $contentChunks): string
    {
        $out = '';
        foreach ($contentChunks as $c) {
            $out .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $c]]]])."\n\n";
        }

        return $out."data: [DONE]\n\n";
    }

    public function test_llm_client_parses_streamed_deltas(): void
    {
        Http::fake(['*' => Http::response($this->sseBody(['你', '好', '嗎']))]);

        $deltas = [];
        $r = $this->app->make(LlmClient::class)->stream(
            [['role' => 'user', 'content' => 'hi']],
            function ($d) use (&$deltas) { $deltas[] = $d; },
        );

        $this->assertSame(['你', '好', '嗎'], $deltas);
        $this->assertSame('你好嗎', $r['content']);
    }

    public function test_stream_endpoint_emits_sse_and_persists(): void
    {
        $this->actingAs(User::create(['name' => 'T', 'email' => 't@pai.test', 'password' => bcrypt('x')]));

        Http::fakeSequence()
            // 1) MetaRouter.classify → chat
            ->push(['choices' => [['message' => ['content' => json_encode(['category' => 'chat', 'reason' => 'x'])], 'finish_reason' => 'stop']], 'usage' => []])
            // 2) 串流回覆（SSE body 字串）
            ->push($this->sseBody(['哈', '囉']));

        $res = $this->post('/stream/chat', ['message' => '嗨']);
        $res->assertOk();
        $res->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $content = $res->streamedContent();
        $this->assertStringContainsString('event: delta', $content);
        $this->assertStringContainsString('哈', $content);
        $this->assertStringContainsString('event: done', $content);

        $conv = Conversation::latest('id')->first();
        $this->assertCount(2, $conv->messages);
        $this->assertSame('哈囉', $conv->messages[1]->content);
    }

    public function test_action_category_enqueues_and_acks_without_blocking(): void
    {
        $this->actingAs(User::create(['name' => 'T', 'email' => 't@pai.test', 'password' => bcrypt('x')]));
        \Illuminate\Support\Facades\Bus::fake([\App\Pai\Cognition\RouteCommandJob::class]);

        // 只需一次 LLM（判斷類別）→ 動作交背景，不在 SSE 內跑重活
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode(['category' => 'new_domain', 'reason' => 'x'])], 'finish_reason' => 'stop']], 'usage' => []])]);

        $res = $this->post('/stream/chat', ['message' => '監控資料庫慢查詢並建議加索引']);
        $res->assertOk();
        $content = $res->streamedContent();
        $this->assertStringContainsString('event: done', $content);
        $this->assertStringContainsString('背景', $content);

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Pai\Cognition\RouteCommandJob::class);
        $this->assertCount(2, Conversation::latest('id')->first()->messages);
    }
}
