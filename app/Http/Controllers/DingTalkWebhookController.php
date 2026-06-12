<?php

namespace App\Http\Controllers;

use App\Pai\Chat\DingTalkReplyJob;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DingTalk 機器人 outgoing webhook（雙向）：@機器人 → 收到 → 背景跑對話大腦 →
 * 用訊息內附的 sessionWebhook 回覆。以機器人 Signing Secret 驗證 timestamp+sign。
 */
class DingTalkWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings): JsonResponse
    {
        $secret = (string) $settings->get('dingtalk.app_secret', '');
        $ts = (string) $request->header('timestamp', '');
        $sign = (string) $request->header('sign', '');
        if ($secret !== '') {
            if ($ts === '' || abs((int) (microtime(true) * 1000) - (int) $ts) > 3600000) {
                return response()->json(['error' => 'stale'], 401);
            }
            $expected = base64_encode(hash_hmac('sha256', $ts, $secret, true));
            if (! hash_equals($expected, $sign)) {
                return response()->json(['error' => 'bad signature'], 401);
            }
        }

        $text = trim((string) $request->input('text.content', ''));
        $sessionWebhook = (string) $request->input('sessionWebhook', '');
        $convKey = (string) ($request->input('conversationId') ?? $request->input('senderId') ?? 'dingtalk');
        if ($text !== '' && $sessionWebhook !== '') {
            DingTalkReplyJob::dispatch($convKey, $text, $sessionWebhook);
        }

        return response()->json(['ok' => true]);
    }
}
