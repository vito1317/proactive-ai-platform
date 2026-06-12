<?php

namespace App\Http\Controllers;

use App\Pai\Chat\FeishuReplyJob;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Feishu / Lark Events API（雙向）：@提及或私訊 bot → 收到 → 背景跑對話大腦 → 用 im API 回覆。
 * 自動處理 url_verification challenge；verification_token 驗證；event_id 去重。
 */
class FeishuWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings): JsonResponse
    {
        // URL 驗證 challenge
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        // 驗證 token（明文事件：header.token 或 schema 2.0 的 header.token）
        $token = (string) $settings->get('feishu.verification_token', '');
        $reqToken = (string) ($request->input('header.token') ?? $request->input('token'));
        if ($token !== '' && $reqToken !== '' && ! hash_equals($token, $reqToken)) {
            return response()->json(['error' => 'bad token'], 401);
        }

        $eventId = (string) ($request->input('header.event_id') ?? $request->input('uuid') ?? '');
        if ($eventId !== '' && Cache::has('feishu:evt:'.$eventId)) {
            return response()->json(['ok' => true]);
        }
        if ($eventId !== '') {
            Cache::put('feishu:evt:'.$eventId, true, 600);
        }

        $eventType = (string) ($request->input('header.event_type') ?? $request->input('event.type'));
        if ($eventType === 'im.message.receive_v1') {
            $msg = (array) $request->input('event.message', []);
            $chatId = (string) ($msg['chat_id'] ?? '');
            $msgType = (string) ($msg['message_type'] ?? '');
            $text = '';
            if ($msgType === 'text') {
                $content = json_decode((string) ($msg['content'] ?? '{}'), true);
                $text = trim((string) ($content['text'] ?? ''));
                $text = preg_replace('/@_user_\d+/', '', $text); // 去掉 @bot 標記
            }
            if (trim($text) !== '' && $chatId !== '') {
                FeishuReplyJob::dispatch($chatId, trim($text));
            }
        }

        return response()->json(['ok' => true]);
    }
}
