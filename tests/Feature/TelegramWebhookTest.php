<?php

namespace Tests\Feature;

use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Chat\TelegramReplyJob;
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

    public function test_dispatches_reply_job(): void
    {
        Bus::fake([TelegramReplyJob::class]);
        $this->app->make(Settings::class)->set('notify.telegram.webhook_secret', 'SECRET');

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SECRET'])
            ->postJson('/webhooks/telegram', ['message' => ['chat' => ['id' => 123], 'text' => '你好']])
            ->assertOk();

        Bus::assertDispatched(TelegramReplyJob::class, fn ($j) => $j->chatId === '123' && $j->text === '你好');
    }

    public function test_reply_job_responds_and_sends_back(): void
    {
        $this->app->make(Settings::class)->set('notify.telegram.token', 'TOK');
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => json_encode(['category' => 'chat', 'reason' => 'x'])], 'finish_reason' => 'stop']], 'usage' => []])
            ->push(['choices' => [['message' => ['content' => '哈囉，我能幫你什麼？'], 'finish_reason' => 'stop']], 'usage' => []])
            ->push(['ok' => true]); // sendMessage

        (new TelegramReplyJob('123', '你好'))->handle($this->app->make(ChatResponder::class), $this->app->make(Notifier::class));

        $conv = Conversation::where('tg_chat_id', '123')->first();
        $this->assertNotNull($conv);
        $this->assertCount(2, $conv->messages);
        $this->assertSame('哈囉，我能幫你什麼？', $conv->messages[1]->content);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org/botTOK/sendMessage')
            && $r['chat_id'] === '123' && $r['text'] === '哈囉，我能幫你什麼？');
    }
}
