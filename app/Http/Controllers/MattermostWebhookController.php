<?php

namespace App\Http\Controllers;

use App\Pai\Chat\MattermostReplyJob;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mattermost outgoing webhook（雙向）：在頻道觸發 → 收到（form：token/channel_id/text/user_name）→
 * 背景跑對話大腦 → 用 bot token 經 /api/v4/posts 回覆。以 outgoing webhook token 驗證。
 */
class MattermostWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings): JsonResponse
    {
        $token = (string) $settings->get('mattermost.token', '');
        if ($token !== '' && ! hash_equals($token, (string) $request->input('token'))) {
            return response()->json(['error' => 'bad token'], 401);
        }
        // bot 自己發的（含 bot 帳號）不回，避免迴圈
        if ($request->input('user_name') === 'pai' || $request->boolean('from_bot')) {
            return response()->json(['ok' => true]);
        }
        $text = trim((string) $request->input('text', ''));
        $channelId = (string) $request->input('channel_id', '');
        // 去掉觸發詞（trigger word）前綴
        $trigger = (string) $request->input('trigger_word', '');
        if ($trigger !== '' && str_starts_with($text, $trigger)) {
            $text = trim(substr($text, strlen($trigger)));
        }
        if ($text !== '' && $channelId !== '') {
            MattermostReplyJob::dispatch($channelId, $text);
        }

        return response()->json(['ok' => true]); // 同步回空，真結果由 bot token 非同步發
    }
}
