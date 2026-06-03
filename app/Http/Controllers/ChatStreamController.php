<?php

namespace App\Http\Controllers;

use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Cognition\LlmClient;
use App\Pai\Cognition\RouteCommandJob;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 對話的 SSE 即時串流：逐 token 推送 AI 回覆。
 * 路徑置於 /stream/ 下，對應 nginx 已關閉 buffering 的 location。
 */
class ChatStreamController extends Controller
{
    public function stream(Request $request, ChatResponder $responder, LlmClient $llm): StreamedResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $conv = $this->resolve($request, $data['conversation_id'] ?? null);
        if ($conv->title === null) {
            $conv->update(['title' => Str::limit($data['message'], 30)]);
        }
        $conv->addMessage('user', $data['message']);
        $message = $data['message'];

        // 釋放 session 鎖，避免長連線阻塞同使用者的其他請求（輪詢/重載）
        $request->session()->save();

        return response()->stream(function () use ($responder, $llm, $conv, $message) {
            // SSE 為長連線：解除 PHP 30s 執行上限；即使使用者離開也把回覆存完
            @set_time_limit(0);
            ignore_user_abort(true);

            $emit = function (string $event, array $payload) {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                // 1) 待確認的高風險技能（使用者回「確認/取消」）—— 直接處理
                if ($resolved = $responder->skills()->resolvePending($conv, $message)) {
                    $this->emitTyped($emit, $resolved['reply']);
                    $conv->addMessage('assistant', $resolved['reply'], $resolved['meta']);
                    $emit('done', ['conversation_id' => $conv->id, 'meta' => $resolved['meta']]);

                    return;
                }

                $emit('status', ['text' => '判斷意圖中…']);
                $category = $responder->category($conv, $message);

                $reply = '';
                $meta = ['category' => $category];

                if ($category === 'chat') {
                    // 閒聊：逐 token 串流（持續有資料，不會閒置）
                    $emit('status', ['text' => '思考中…']);
                    $full = '';
                    $llm->stream(
                        $responder->chatMessages($conv),
                        function (string $delta) use (&$full, $emit) {
                            $full .= $delta;
                            $emit('delta', ['text' => $delta]);
                        },
                        fn () => $emit('status', ['text' => '思考中…']),
                    );
                    $reply = trim($full) ?: '（沒有產生回覆）';
                } elseif ($category === 'skill') {
                    // 平台操作技能：同步執行（快），高風險則回覆要求對話確認
                    $emit('status', ['text' => '處理中…']);
                    $result = $responder->skills()->handle($conv, $message);
                    $reply = $result['reply'];
                    $meta = $result['meta'];
                    $this->emitTyped($emit, $reply);
                } else {
                    // 需執行動作（任務/新增領域/設定通知）：交給背景 queue 處理，
                    // SSE 立即回覆，避免長時間無資料造成連線被切（結果以通知回報）。
                    $event = PaiEvent::create([
                        'source' => 'chat', 'topic' => 'console.request',
                        'payload' => ['message' => $message], 'status' => EventStatus::Received,
                    ]);
                    RouteCommandJob::dispatch($event->id);

                    $reply = match ($category) {
                        'task' => '好的，我判斷這是一個任務，已在背景交給對應領域協調者處理。完成後可在中控台「AI 認知運行」看到推理與處置；若有高風險動作會通知你核准。',
                        'new_domain' => '好的，我正在背景依你的描述生成並啟用新領域包 🧩，完成後會在 🔔 通知告訴你（也會出現在「領域包」頁）。',
                        'configure_notify' => '好的，我在背景幫你設定通知並發送測試，結果會以 🔔 通知回報。',
                        default => '好的，我來處理。',
                    };
                    foreach (mb_str_split($reply, 6) as $chunk) {  // 逐字輸出
                        $emit('delta', ['text' => $chunk]);
                        usleep(10000);
                    }
                }

                $conv->addMessage('assistant', $reply, $meta);
                $emit('done', ['conversation_id' => $conv->id, 'meta' => $meta]);
            } catch (Throwable $e) {
                $emit('error', ['text' => 'AI 回覆失敗：'.$e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /** 逐字輸出一段已完成的文字（給技能/確認類即時結果）。 */
    private function emitTyped(callable $emit, string $text): void
    {
        foreach (mb_str_split($text, 6) as $chunk) {
            $emit('delta', ['text' => $chunk]);
            usleep(8000);
        }
    }

    private function resolve(Request $request, ?int $id): Conversation
    {
        if ($id) {
            $conv = Conversation::where('user_id', $request->user()->id)->find($id);
            if ($conv) {
                return $conv;
            }
        }

        return Conversation::create(['user_id' => $request->user()->id]);
    }
}
