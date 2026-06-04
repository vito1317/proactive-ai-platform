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

    public function __construct(public string $to, public string $text, public ?string $imageMessageId = null, public ?string $audioMessageId = null)
    {
        $this->onQueue('chat'); // 互動回覆走獨立佇列，不被重型任務阻塞
    }

    public function handle(ChatResponder $responder, LlmClient $llm, Notifier $notifier, MediaFetcher $media, SpeechToText $stt): void
    {
        $conv = Conversation::forLine($this->to);

        $last = 0;
        $loading = function () use ($notifier, &$last) {
            // LINE 載入動畫一次最長 60s → 每 50s 續發；僅 1:1（userId 以 U 開頭）支援
            if (str_starts_with($this->to, 'U') && time() - $last >= 50) {
                $last = time();
                $notifier->sendLineLoading($this->to, 60);
            }
        };
        $loading(); // 收到訊息立刻顯示載入動畫
        LlmClient::setHeartbeat($loading); // 所有 LLM 等待期間自動續發載入動畫

        // 語音：先轉文字
        if ($this->audioMessageId) {
            $b64 = $media->lineAudio($this->audioMessageId);
            $transcript = $b64 ? $stt->transcribe($b64) : null;
            if (! $transcript) {
                LlmClient::setHeartbeat(null);
                $conv->addMessage('user', '🎤（語音，轉錄失敗）');
                $notifier->sendLineTo($this->to, '抱歉，這段語音我聽不清楚或轉錄失敗，可以再說一次或改打字嗎？');

                return;
            }
            $this->text = $transcript;
        }

        $userText = $this->imageMessageId ? ('[圖片] '.$this->text) : ($this->audioMessageId ? ('🎤 '.$this->text) : $this->text);
        if ($conv->title === null) {
            $conv->update(['title' => Str::limit($userText !== '' ? $userText : '圖片', 30)]);
        }
        $conv->addMessage('user', $userText);

        try {
            // 多模態：有圖片 → 走 vision 回答
            if ($this->imageMessageId) {
                $uri = $media->line($this->imageMessageId);
                $reply = $uri
                    ? $responder->visionReply($conv, $this->text, $uri)
                    : '我收到圖片了，但下載失敗，請再傳一次或改用文字描述。';
                $conv->addMessage('assistant', $reply, ['category' => 'vision']);
                $notifier->sendLineTo($this->to, $reply);

                return;
            }

            $routed = $responder->route($conv, $this->text);

            if ($routed['stream']) {
                // 串流生成：delta 同時是動畫 heartbeat，逾 50 秒自動續發
                $out = $llm->stream($routed['messages'], $loading, null, $loading);
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
        $buttons = ! empty($meta['pending']) ? ['確認', '一律允許', '取消'] : [];
        $notifier->sendLineTo($this->to, $reply, $buttons);
    }
}
