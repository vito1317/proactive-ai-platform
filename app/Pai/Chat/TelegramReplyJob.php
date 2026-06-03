<?php

namespace App\Pai\Chat;

use App\Pai\Cognition\LlmClient;
use App\Pai\Notify\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * 處理一則來自 Telegram 的訊息：用對話大腦回應（帶該 chat 的上下文），
 * 再把回覆送回該 Telegram chat。跑在 queue 上（LLM 可能數十秒）。
 *
 * 全程維持「輸入中…」動畫：收到先發 sendChatAction，閒聊改走串流生成，
 * 每個 delta 當 heartbeat 節流補發（TG 動畫約 5 秒過期）。
 */
class TelegramReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $chatId, public string $text, public ?string $imageFileId = null)
    {
        $this->onQueue('chat'); // 互動回覆走獨立佇列，不被重型任務阻塞
    }

    public function handle(ChatResponder $responder, LlmClient $llm, Notifier $notifier, MediaFetcher $media): void
    {
        $conv = Conversation::forTelegram($this->chatId);
        $userText = $this->imageFileId ? ('[圖片] '.$this->text) : $this->text;
        if ($conv->title === null) {
            $conv->update(['title' => Str::limit($userText !== '' ? $userText : '圖片', 30)]);
        }
        $conv->addMessage('user', $userText);

        $last = 0;
        $typing = function () use ($notifier, &$last) {
            if (time() - $last >= 4) { // 動畫約 5 秒過期 → 每 4 秒補發
                $last = time();
                $notifier->sendTelegramTyping($this->chatId);
            }
        };
        $typing(); // 收到訊息立刻顯示「輸入中…」
        LlmClient::setHeartbeat($typing); // 之後所有 LLM 等待（分類/動作/串流）都會持續心跳

        try {
            // 多模態：有圖片 → 走 vision 回答
            if ($this->imageFileId) {
                $uri = $media->telegram($this->imageFileId);
                $reply = $uri
                    ? $responder->visionReply($conv, $this->text, $uri)
                    : '我收到圖片了，但下載失敗，請再傳一次或改用文字描述。';
                $meta = ['category' => 'vision'];
                $conv->addMessage('assistant', $reply, $meta);
                $notifier->sendTelegramTo($this->chatId, $reply);

                return;
            }

            $routed = $responder->route($conv, $this->text);

            if ($routed['stream']) {
                // 串流生成：每個 delta 同時是動畫 heartbeat，「輸入中…」不中斷
                $out = $llm->stream($routed['messages'], $typing, null, $typing);
                $reply = trim($out['content']) !== '' ? trim($out['content']) : '抱歉，我這次沒有產生回覆，請再試一次。';
                $meta = ['category' => 'chat'];
            } else {
                $reply = $routed['reply'];
                $meta = $routed['meta'];
            }
        } catch (Throwable $e) {
            $reply = '抱歉，我處理時發生問題：'.$e->getMessage();
            $meta = ['error' => true];
        } finally {
            LlmClient::setHeartbeat(null);
        }

        $conv->addMessage('assistant', $reply, $meta);
        $notifier->sendTelegramTo($this->chatId, $reply);
    }
}
