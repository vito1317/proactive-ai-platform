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

class VisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_fetcher_downloads_telegram_image_as_data_uri(): void
    {
        $this->app->make(Settings::class)->set('notify.telegram.token', 'TOK');
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['result' => ['file_path' => 'photos/abc.jpg']]),
            'api.telegram.org/file/*' => Http::response('JPEGBYTES'),
        ]);

        $uri = $this->app->make(MediaFetcher::class)->telegram('fileid');

        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);
        $this->assertSame('JPEGBYTES', base64_decode(substr($uri, strpos($uri, ',') + 1)));
    }

    public function test_vision_reply_sends_multimodal_message(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '這是一隻貓'], 'finish_reason' => 'stop']], 'usage' => [],
        ])]);
        $conv = Conversation::create([]);
        $conv->addMessage('user', '[圖片] 這是什麼');

        $reply = $this->app->make(ChatResponder::class)->visionReply($conv, '這是什麼', 'data:image/jpeg;base64,AAAA');

        $this->assertSame('這是一隻貓', $reply);
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'image_url')
            && str_contains(json_encode($req->data()), 'base64,AAAA'));
    }

    public function test_telegram_image_message_gets_vision_reply(): void
    {
        $this->app->make(Settings::class)->set('notify.telegram.token', 'TOK');
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response(['result' => ['file_path' => 'photos/x.jpg']]),
            'api.telegram.org/file/*' => Http::response('IMG'),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            '*' => Http::response(['choices' => [['message' => ['content' => '看起來是一張貓的照片'], 'finish_reason' => 'stop']], 'usage' => []]),
        ]);

        (new TelegramReplyJob('123', '這是什麼', 'fileid'))->handle(
            $this->app->make(ChatResponder::class),
            $this->app->make(LlmClient::class),
            $this->app->make(Notifier::class),
            $this->app->make(MediaFetcher::class),
            $this->app->make(SpeechToText::class),
        );

        $conv = Conversation::where('tg_chat_id', '123')->first();
        $this->assertNotNull($conv);
        $this->assertCount(2, $conv->messages);
        $this->assertStringContainsString('[圖片]', $conv->messages[0]->content);
        $this->assertStringContainsString('貓', $conv->messages[1]->content);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains((string) ($r['text'] ?? ''), '貓'));
    }
}
