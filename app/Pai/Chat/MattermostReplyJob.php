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

/** 處理一則 Mattermost 訊息：對話大腦回應 → 用 bot token 經 /api/v4/posts 回覆到該頻道。 */
class MattermostReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $channelId, public string $text)
    {
        $this->onQueue('chat');
    }

    public function handle(ChatResponder $responder, Settings $settings): void
    {
        $sid = 'mattermost:'.$this->channelId;
        $ownerId = User::where('role', 'admin')->orderBy('id')->min('id') ?? User::query()->min('id');
        $conv = Conversation::where('voice_sid', $sid)->latest('id')->first()
            ?? Conversation::create(['voice_sid' => $sid, 'user_id' => $ownerId, 'title' => Str::limit($this->text, 30)]);

        $conv->addMessage('user', $this->text, ['source' => 'mattermost']);
        $reply = '（沒有產生回覆）';
        try {
            $r = $responder->respond($conv, $this->text);
            $reply = $r['reply'] ?: $reply;
        } catch (Throwable $e) {
            $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
        }
        $conv->addMessage('assistant', $reply, ['source' => 'mattermost']);
        \App\Pai\Memory\ExtractMemoryJob::dispatch($this->text, $reply, $conv->user_id);

        $base = rtrim((string) $settings->get('mattermost.base_url', ''), '/');
        $token = (string) $settings->get('mattermost.bot_token', '');
        if ($base !== '' && $token !== '') {
            try {
                Http::timeout(20)->withToken($token)->post($base.'/api/v4/posts', [
                    'channel_id' => $this->channelId,
                    'message' => mb_substr($reply, 0, 4000),
                ]);
            } catch (Throwable) {
            }
        }
    }
}
