<?php

namespace Tests\Feature;

use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Chat\LineReplyJob;
use App\Pai\Chat\TelegramReplyJob;
use App\Pai\Cognition\LlmClient;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_wrong_secret(): void
    {
        $this->app->make(Settings::class)->set('notify.telegram.webhook_secret', 'SECRET');
        $this->postJson('/webhooks/telegram', ['message' => ['chat' => ['id' => 1], 'text' => 'hi']])
            ->assertStatus(403);
    }

    public function test_dispatches_reply_job_and_shows_typing(): void
    {
        Bus::fake([TelegramReplyJob::class]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $s = $this->app->make(Settings::class);
        $s->set('notify.telegram.webhook_secret', 'SECRET');
        $s->set('notify.telegram.token', 'TOK');

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
            ->postJson('/webhooks/telegram', ['message' => ['chat' => ['id' => 123, 'type' => 'private'], 'text' => '你好']])
            ->assertOk();

        Bus::assertDispatched(TelegramReplyJob::class, fn ($j) => $j->chatId === '123' && $j->text === '你好');
        // 收到當下立即顯示「輸入中…」動畫
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendChatAction') && $r['action'] === 'typing');
    }

    public function test_new_command_creates_fresh_session(): void
    {
        Bus::fake([TelegramReplyJob::class]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $s = $this->app->make(Settings::class);
        $s->set('notify.telegram.webhook_secret', 'SECRET');
        $s->set('notify.telegram.token', 'TOK');
        $old = Conversation::forTelegram('123');

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
            ->postJson('/webhooks/telegram', ['message' => ['chat' => ['id' => 123, 'type' => 'private'], 'text' => '/new']])
            ->assertOk();

        Bus::assertNotDispatched(TelegramReplyJob::class);
        $this->assertNotSame($old->id, Conversation::forTelegram('123')->id); // 新 session
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], '新的會話'));
    }

    public function test_reply_job_streams_reply_with_typing_heartbeat(): void
    {
        $this->app->make(Settings::class)->set('notify.telegram.token', 'TOK');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            // LLM 端點與 telegram 分開 stub，避免 '*' 萬用 pattern 搶走 telegram 請求
            '127.0.0.1*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode(['category' => 'chat', 'reason' => 'x'])], 'finish_reason' => 'stop']], 'usage' => []])
                ->push('data: '.json_encode(['choices' => [['delta' => ['content' => '哈囉，我能幫你什麼？']]]])."\n\ndata: [DONE]\n\n"),
        ]);

        (new TelegramReplyJob('123', '你好'))
            ->handle($this->app->make(ChatResponder::class), $this->app->make(LlmClient::class), $this->app->make(Notifier::class));

        $conv = Conversation::where('tg_chat_id', '123')->first();
        $this->assertNotNull($conv);
        $this->assertCount(2, $conv->messages);
        $this->assertSame('哈囉，我能幫你什麼？', $conv->messages[1]->content);
        $this->assertSame('你好', $conv->title); // 首則訊息成為 session 標題

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendChatAction') && $r['action'] === 'typing');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org/botTOK/sendMessage')
            && $r['chat_id'] === '123' && $r['text'] === '哈囉，我能幫你什麼？');
    }

    public function test_line_webhook_dispatches_and_shows_loading(): void
    {
        Bus::fake([LineReplyJob::class]);
        Http::fake(['api.line.me/*' => Http::response([])]);
        $s = $this->app->make(Settings::class);
        $s->set('notify.line.secret', 'LSECRET');
        $s->set('notify.line.token', 'LTOK');

        $body = json_encode(['events' => [[
            'type' => 'message', 'replyToken' => 'rt',
            'source' => ['type' => 'user', 'userId' => 'U1234567890'],
            'message' => ['type' => 'text', 'text' => '嗨'],
        ]]]);
        $sig = base64_encode(hash_hmac('sha256', $body, 'LSECRET', true));

        $this->call('POST', '/webhooks/line', [], [], [], [
            'HTTP_X-Line-Signature' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        Bus::assertDispatched(LineReplyJob::class, fn ($j) => $j->to === 'U1234567890' && $j->text === '嗨');
        // 收到當下立即顯示載入動畫
        Http::assertSent(fn ($r) => str_contains($r->url(), 'chat/loading/start') && $r['chatId'] === 'U1234567890');
    }

    public function test_line_webhook_rejects_bad_signature(): void
    {
        $this->app->make(Settings::class)->set('notify.line.secret', 'LSECRET');

        $this->call('POST', '/webhooks/line', [], [], [], [
            'HTTP_X-Line-Signature' => 'bad', 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['events' => []]))->assertStatus(403);
    }
}
