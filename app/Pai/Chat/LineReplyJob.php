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
 * 處理一則來自 LINE 的訊息：用對話大腦回應（帶該對象的上下文），
 * 再用 push API 回覆。跑在 queue 上（LLM 可能數十秒，故不用會過期的 replyToken）。
 *
 * 全程維持「載入中」動畫：收到先打 chat/loading/start（最長 60 秒、僅 1:1 有效），
 * 串流生成時每個 delta 當 heartbeat，逾 50 秒自動續發；bot 回覆時動畫自動消失。
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

    public function handle(ChatResponder $responder, LlmClient $llm, Notifier $notifier): void
    {
        $conv = Conversation::forLine($this->to);
        if ($conv->title === null) {
            $conv->update(['title' => Str::limit($this->text, 30)]);
        }
        $conv->addMessage('user', $this->text);

        $last = 0;
        $loading = function () use ($notifier, &$last) {
            // LINE 載入動畫一次最長 60s → 每 50s 續發；僅 1:1（userId 以 U 開頭）支援
            if (str_starts_with($this->to, 'U') && time() - $last >= 50) {
                $last = time();
                $notifier->sendLineLoading($this->to, 60);
            }
        };
        $loading(); // 收到訊息立刻顯示載入動畫

        try {
            $category = $responder->category($conv, $this->text);

            if ($category === 'chat') {
                // 串流生成：delta 同時是動畫 heartbeat，逾 50 秒自動續發
                $out = $llm->stream($responder->chatMessages($conv), $loading, null, $loading);
                $reply = trim($out['content']) !== '' ? trim($out['content']) : '抱歉，我這次沒有產生回覆，請再試一次。';
                $meta = ['category' => 'chat'];
            } else {
                $result = $responder->act($category, $this->text);
                $reply = $result['reply'];
                $meta = $result['meta'];
            }
        } catch (Throwable $e) {
            $reply = '抱歉，我處理時發生問題：'.$e->getMessage();
            $meta = ['error' => true];
        }

        $conv->addMessage('assistant', $reply, $meta);
        $notifier->sendLineTo($this->to, $reply);
    }
}
