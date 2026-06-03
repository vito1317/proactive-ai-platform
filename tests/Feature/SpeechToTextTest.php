<?php

namespace Tests\Feature;

use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Chat\MediaFetcher;
use App\Pai\Chat\SpeechToText;
use App\Pai\Chat\TelegramReplyJob;
use App\Pai\Cognition\LlmClient;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpeechToTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_transcribe_calls_stt_endpoint(): void
    {
        $this->app->make(Settings::class)->set('voice.stt_url', 'http://127.0.0.1:8891/voice/transcribe');
        Http::fake(['*/voice/transcribe' => Http::response(['success' => true, 'text' => '今天天氣如何'])]);

        $text = $this->app->make(SpeechToText::class)->transcribe(base64_encode('AUDIO'));

        $this->assertSame('今天天氣如何', $text);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/voice/transcribe') && $r['audio_base64'] === base64_encode('AUDIO'));
    }

    public function test_media_fetcher_telegram_audio_returns_base64(): void
    {
        $this->app->make(Settings::class)->set('notify.telegram.token', 'TOK');
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['result' => ['file_path' => 'voice/x.oga']]),
            'api.telegram.org/file/*' => Http::response('OGGBYTES'),
        ]);

        $b64 = $this->app->make(MediaFetcher::class)->telegramAudio('fid');
        $this->assertSame('OGGBYTES', base64_decode($b64));
    }

    public function test_telegram_voice_message_is_transcribed_then_processed(): void
    {
        $s = $this->app->make(Settings::class);
        $s->set('notify.telegram.token', 'TOK');
        $s->set('voice.stt_url', 'http://127.0.0.1:8891/voice/transcribe');
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['result' => ['file_path' => 'voice/x.oga']]),
            'api.telegram.org/file/*' => Http::response('OGG'),
            '*/voice/transcribe' => Http::response(['success' => true, 'text' => '幫我查一下狀態']),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            '*' => Http::response(['choices' => [['message' => ['content' => json_encode(['category' => 'chat', 'reason' => 'x'])], 'finish_reason' => 'stop']], 'usage' => []]),
        ]);

        (new TelegramReplyJob('555', '', null, 'voicefid'))->handle(
            $this->app->make(ChatResponder::class),
            $this->app->make(LlmClient::class),
            $this->app->make(Notifier::class),
            $this->app->make(MediaFetcher::class),
            $this->app->make(SpeechToText::class),
        );

        $conv = Conversation::where('tg_chat_id', '555')->first();
        $this->assertNotNull($conv);
        $this->assertStringContainsString('🎤 幫我查一下狀態', $conv->messages[0]->content);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/voice/transcribe'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }
}
