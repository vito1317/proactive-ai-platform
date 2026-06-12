<?php

namespace App\Pai\Chat;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** 處理一則 DingTalk 訊息：對話大腦回應 → 用訊息附的 sessionWebhook 回覆。 */
class DingTalkReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $convKey, public string $text, public string $sessionWebhook)
    {
        $this->onQueue('chat');
    }

    public function handle(ChatResponder $responder): void
    {
        $sid = 'dingtalk:'.$this->convKey;
        $ownerId = User::where('role', 'admin')->orderBy('id')->min('id') ?? User::query()->min('id');
        $conv = Conversation::where('voice_sid', $sid)->latest('id')->first()
            ?? Conversation::create(['voice_sid' => $sid, 'user_id' => $ownerId, 'title' => Str::limit($this->text, 30)]);

        $conv->addMessage('user', $this->text, ['source' => 'dingtalk']);
        $reply = '（沒有產生回覆）';
        try {
            $r = $responder->respond($conv, $this->text);
            $reply = $r['reply'] ?: $reply;
        } catch (Throwable $e) {
            $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
        }
        $conv->addMessage('assistant', $reply, ['source' => 'dingtalk']);
        \App\Pai\Memory\ExtractMemoryJob::dispatch($this->text, $reply, $conv->user_id);

        try {
            Http::timeout(20)->post($this->sessionWebhook, [
                'msgtype' => 'text',
                'text' => ['content' => mb_substr($reply, 0, 3000)],
            ]);
        } catch (Throwable) {
        }
    }
}
