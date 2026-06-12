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
 * 處理一則來自 Discord /ask 的訊息：用對話大腦回應（帶該頻道的上下文 + 啟用人格），
 * 再用 interaction token 編輯原本的「思考中…」訊息成真結果。跑在 queue（LLM 可能數十秒）。
 */
class DiscordReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $channelId, public string $text, public string $token)
    {
        $this->onQueue('chat');
    }

    public function handle(ChatResponder $responder, Settings $settings): void
    {
        $sid = 'discord:'.$this->channelId;
        // Discord 是平台層級單一 bot → 對話歸屬主 admin（多租戶頻道對應日後可擴充）
        $ownerId = User::where('role', 'admin')->orderBy('id')->min('id') ?? User::query()->min('id');
        $conv = Conversation::where('voice_sid', $sid)->latest('id')->first()
            ?? Conversation::create(['voice_sid' => $sid, 'user_id' => $ownerId, 'title' => Str::limit($this->text, 30)]);

        $conv->addMessage('user', $this->text, ['source' => 'discord']);

        $reply = '（沒有產生回覆）';
        try {
            $r = $responder->respond($conv, $this->text);
            $reply = $r['reply'] ?: $reply;
        } catch (Throwable $e) {
            $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
        }
        $conv->addMessage('assistant', $reply, ['source' => 'discord']);
        \App\Pai\Memory\ExtractMemoryJob::dispatch($this->text, $reply, $conv->user_id);

        // 編輯原本的延遲訊息（@original）成真結果
        $appId = (string) $settings->get('discord.app_id', '');
        if ($appId !== '') {
            try {
                Http::timeout(20)->patch(
                    "https://discord.com/api/v10/webhooks/{$appId}/{$this->token}/messages/@original",
                    ['content' => mb_substr($reply, 0, 1900)]
                );
            } catch (Throwable) {
            }
        }
    }
}
