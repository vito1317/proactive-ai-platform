<?php

namespace App\Http\Controllers;

use App\Pai\Chat\Conversation;
use App\Pai\Chat\LineReplyJob;
use App\Pai\Notify\ChannelRegistry;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LINE Messaging API 接收 webhook（雙向）。使用者在 LINE 對 bot 傳訊 → 收到 →
 * 對話大腦處理 → 用 push API 回覆。以 Channel secret 驗證簽章。
 */
class LineWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings, ChannelRegistry $channels, Notifier $notifier): JsonResponse
    {
        // 用 LINE Channel secret 驗證簽章（X-Line-Signature = base64(HMAC-SHA256(body)))
        $secret = $settings->get('notify.line.secret');
        if ($secret) {
            $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
            if (! hash_equals($expected, (string) $request->header('X-Line-Signature'))) {
                return response()->json(['ok' => false], 403);
            }
        }

        foreach ((array) $request->input('events', []) as $event) {
            $to = data_get($event, 'source.groupId')
                ?? data_get($event, 'source.roomId')
                ?? data_get($event, 'source.userId');
            if (! $to) {
                continue;
            }
            // 登錄頻道供後台選取/查看
            $channels->remember('line', (string) $to, [
                'type' => data_get($event, 'source.type', 'user'),
                'title' => (string) $to,
            ]);

            if (($event['type'] ?? '') === 'message' && data_get($event, 'message.type') === 'text') {
                $text = trim((string) data_get($event, 'message.text', ''));
                if (strtolower($text) === '/new') {
                    // /new：開新會話 session，舊上下文保留在後台
                    Conversation::newSession('line', (string) $to);
                    $notifier->sendLineTo((string) $to, '🆕 已開啟新的會話，上下文已重置。直接說話即可開始！');
                } elseif ($text !== '') {
                    LineReplyJob::dispatch((string) $to, $text);
                    if (str_starts_with((string) $to, 'U')) {
                        $notifier->sendLineLoading((string) $to, 60); // 收到當下立即顯示載入動畫（僅 1:1）
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
