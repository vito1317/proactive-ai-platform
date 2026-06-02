<?php

namespace App\Pai\Chat;

use App\Pai\Notify\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 處理一則來自 LINE 的訊息：用對話大腦回應（帶該對象的上下文），
 * 再用 push API 回覆。跑在 queue 上（LLM 可能數十秒，故不用會過期的 replyToken）。
 */
class LineReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $to, public string $text)
    {
        $this->onQueue('chat'); // 互動回覆走獨立佇列，不被重型任務阻塞
    }

    public function handle(ChatResponder $responder, Notifier $notifier): void
    {
        $conv = Conversation::forLine($this->to);
        $conv->addMessage('user', $this->text);

        try {
            $result = $responder->respond($conv, $this->text);
            $reply = $result['reply'];
            $meta = $result['meta'];
        } catch (Throwable $e) {
            $reply = '抱歉，我處理時發生問題：'.$e->getMessage();
            $meta = ['error' => true];
        }

        $conv->addMessage('assistant', $reply, $meta);
        $notifier->sendLineTo($this->to, $reply);
    }
}
