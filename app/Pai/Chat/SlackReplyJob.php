<?php

namespace App\Pai\Chat;

use App\Models\User;
use App\Pai\Settings\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * 處理一則來自 Slack 的訊息：用對話大腦回應（帶該頻道上下文 + 啟用人格），
 * 再用 chat.postMessage 回覆到該頻道。跑在 queue（LLM 可能數十秒）。
 */
class SlackReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $channel, public string $text)
    {
        $this->onQueue('chat');
    }

    public function handle(ChatResponder $responder, Settings $settings): void
    {
        $sid = 'slack:'.$this->channel;
        $ownerId = User::where('role', 'admin')->orderBy('id')->min('id') ?? User::query()->min('id');
        $conv = Conversation::where('voice_sid', $sid)->latest('id')->first()
            ?? Conversation::create(['voice_sid' => $sid, 'user_id' => $ownerId, 'title' => Str::limit($this->text, 30)]);

        $conv->addMessage('user', $this->text, ['source' => 'slack']);

        $reply = '（沒有產生回覆）';
        try {
            $r = $responder->respond($conv, $this->text);
            $reply = $r['reply'] ?: $reply;
        } catch (Throwable $e) {
            $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
        }
        $conv->addMessage('assistant', $reply, ['source' => 'slack']);
        \App\Pai\Memory\ExtractMemoryJob::dispatch($this->text, $reply, $conv->user_id);

        $token = (string) $settings->get('slack.bot_token', '');
        if ($token !== '') {
            try {
                Http::timeout(20)->withToken($token)
                    ->post('https://slack.com/api/chat.postMessage', [
                        'channel' => $this->channel,
                        'text' => mb_substr($reply, 0, 3500),
                    ]);
            } catch (Throwable) {
            }
        }
    }
}
