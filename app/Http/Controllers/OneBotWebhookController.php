<?php

namespace App\Http\Controllers;

use App\Pai\Chat\GenericChannelReplyJob;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QQ via OneBot v11（go-cqhttp / NapCat 等）：daemon 把訊息事件 POST 進來 →
 * 背景跑對話大腦 → 用 OneBot HTTP API 回覆。可用 X-Signature(HMAC-SHA1) 驗證。
 */
class OneBotWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings): JsonResponse
    {
        $secret = (string) $settings->get('onebot.secret', '');
        if ($secret !== '') {
            $sig = (string) $request->header('X-Signature', '');
            $expected = 'sha1='.hash_hmac('sha1', $request->getContent(), $secret);
            if (! hash_equals($expected, $sig)) {
                return response()->json(['error' => 'bad signature'], 401);
            }
        }
        if ($request->input('post_type') === 'message') {
            $msgType = (string) $request->input('message_type', 'private');
            $userId = (string) $request->input('user_id', '');
            $groupId = (string) $request->input('group_id', '');
            $text = trim((string) $request->input('raw_message', $request->input('message', '')));
            $key = $msgType === 'group' ? 'g'.$groupId : 'u'.$userId;
            if ($text !== '' && $key !== '') {
                GenericChannelReplyJob::dispatch('onebot', $key, $text, [
                    'message_type' => $msgType, 'user_id' => $userId, 'group_id' => $groupId,
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
