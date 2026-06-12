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

/** 處理一則 Feishu 訊息：對話大腦回應 → 用 im API 回覆到該 chat。 */
class FeishuReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $chatId, public string $text)
    {
        $this->onQueue('chat');
    }

    public function handle(ChatResponder $responder, Settings $settings): void
    {
        $sid = 'feishu:'.$this->chatId;
        $ownerId = User::where('role', 'admin')->orderBy('id')->min('id') ?? User::query()->min('id');
        $conv = Conversation::where('voice_sid', $sid)->latest('id')->first()
            ?? Conversation::create(['voice_sid' => $sid, 'user_id' => $ownerId, 'title' => Str::limit($this->text, 30)]);

        $conv->addMessage('user', $this->text, ['source' => 'feishu']);
        $reply = '（沒有產生回覆）';
        try {
            $r = $responder->respond($conv, $this->text);
            $reply = $r['reply'] ?: $reply;
        } catch (Throwable $e) {
            $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
        }
        $conv->addMessage('assistant', $reply, ['source' => 'feishu']);
        \App\Pai\Memory\ExtractMemoryJob::dispatch($this->text, $reply, $conv->user_id);

        $appId = (string) $settings->get('feishu.app_id', '');
        $appSecret = (string) $settings->get('feishu.app_secret', '');
        if ($appId === '' || $appSecret === '') {
            return;
        }
        try {
            $tok = Http::timeout(15)->post('https://open.feishu.cn/open-apis/auth/v3/tenant_access_token/internal', [
                'app_id' => $appId, 'app_secret' => $appSecret,
            ])->json('tenant_access_token');
            if (! $tok) {
                return;
            }
            Http::timeout(20)->withToken($tok)->post(
                'https://open.feishu.cn/open-apis/im/v1/messages?receive_id_type=chat_id',
                [
                    'receive_id' => $this->chatId,
                    'msg_type' => 'text',
                    'content' => json_encode(['text' => mb_substr($reply, 0, 3000)], JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (Throwable) {
        }
    }
}
