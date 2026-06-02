<?php

namespace App\Http\Controllers;

use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Cognition\LlmClient;
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
                $emit('status', ['text' => '判斷意圖中…']);
                $category = $responder->category($conv, $message);

                $reply = '';
                $meta = ['category' => $category];

                if ($category === 'chat') {
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
                } else {
                    $emit('status', ['text' => '處理中…']);
                    $r = $responder->act($category, $message);
                    $reply = $r['reply'];
                    $meta = $r['meta'];
                    foreach (mb_str_split($reply, 6) as $chunk) {  // 模擬逐字輸出
                        $emit('delta', ['text' => $chunk]);
                        usleep(12000);
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
