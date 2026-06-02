<?php

namespace Tests\Feature;

use App\Pai\Notify\Notifier;
use App\Pai\Notify\NotifyAssistant;
use App\Pai\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifier_sends_to_telegram_when_configured(): void
    {
        $s = $this->app->make(Settings::class);
        $s->set('notify.telegram.token', 'TOK');
        $s->set('notify.telegram.chat_id', 'CHAT');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $r = $this->app->make(Notifier::class)->send('hello');

        $this->assertTrue($r['telegram']);
        $this->assertFalse($r['line']);
        $this->assertFalse($r['webhook']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org/botTOK/sendMessage')
            && str_contains($req->body(), 'CHAT'));
    }

    public function test_notifier_sends_to_line_when_configured(): void
    {
        $s = $this->app->make(Settings::class);
        $s->set('notify.line.token', 'LTOK');
        $s->set('notify.line.to', 'Uabc');
        Http::fake(['api.line.me/*' => Http::response([])]);

        $r = $this->app->make(Notifier::class)->send('hi');

        $this->assertTrue($r['line']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me/v2/bot/message/push')
            && $req->header('Authorization')[0] === 'Bearer LTOK'
            && str_contains($req->body(), 'Uabc'));
    }

    public function test_nothing_sent_when_unconfigured(): void
    {
        $r = $this->app->make(Notifier::class)->send('x');
        $this->assertSame(['webhook' => false, 'telegram' => false, 'line' => false], $r);
    }

    public function test_assistant_extracts_telegram_config(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'channel' => 'telegram', 'token' => 'TK', 'chat_id' => 'CH', 'reply' => '已抓到 Telegram 設定',
            ])], 'finish_reason' => 'stop']],
            'usage' => [],
        ])]);

        $r = $this->app->make(NotifyAssistant::class)->extract('我的 tg bot token 是 TK，chat id 是 CH');

        $this->assertSame('telegram', $r['channel']);
        $this->assertSame('TK', $r['fields']['notify.telegram.token']);
        $this->assertSame('CH', $r['fields']['notify.telegram.chat_id']);
        $this->assertStringContainsString('Telegram', $r['reply']);
    }
}
