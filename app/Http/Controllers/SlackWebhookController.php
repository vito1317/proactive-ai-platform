<?php

namespace App\Http\Controllers;

use App\Pai\Chat\SlackReplyJob;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Slack Events API 接收 webhook（雙向）：@提及 bot 或私訊 bot → 收到 →
 * 先回 200（3 秒內）→ 背景跑對話大腦 → 用 chat.postMessage 回覆。
 * 以 Signing Secret 驗證 v0 簽章；自動處理 URL 驗證 challenge；event_id 去重（Slack 會重送）。
 */
class SlackWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings): JsonResponse
    {
        $body = $request->getContent();
        $secret = (string) $settings->get('slack.signing_secret', '');
        $ts = (string) $request->header('X-Slack-Request-Timestamp', '');
        $sig = (string) $request->header('X-Slack-Signature', '');

        // 簽章驗證：v0=HMAC-SHA256(secret, "v0:{ts}:{body}")；時間戳 5 分鐘內
        if ($secret !== '') {
            if ($ts === '' || abs(time() - (int) $ts) > 300) {
                return response()->json(['error' => 'stale'], 401);
            }
            $expected = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", $secret);
            if (! hash_equals($expected, $sig)) {
                return response()->json(['error' => 'bad signature'], 401);
            }
        }

        // URL 驗證 challenge（設定 Event Subscriptions 時 Slack 會發）
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        if ($request->input('type') === 'event_callback') {
            // 去重：Slack 重送同一事件 → 用 event_id 擋
            $eventId = (string) $request->input('event_id');
            if ($eventId !== '' && Cache::has('slack:evt:'.$eventId)) {
                return response()->json(['ok' => true]);
            }
            Cache::put('slack:evt:'.$eventId, true, 600);

            $event = (array) $request->input('event', []);
            $type = (string) ($event['type'] ?? '');
            // 只回應 @提及 或 私訊；忽略 bot 自己發的訊息（避免迴圈）
            if (($type === 'app_mention' || ($type === 'message' && ($event['channel_type'] ?? '') === 'im'))
                && empty($event['bot_id']) && empty($event['subtype'])) {
                $text = trim((string) ($event['text'] ?? ''));
                $text = preg_replace('/<@[^>]+>/', '', $text); // 去掉 @bot 標記
                $channel = (string) ($event['channel'] ?? '');
                if (trim($text) !== '' && $channel !== '') {
                    SlackReplyJob::dispatch($channel, trim($text));
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
