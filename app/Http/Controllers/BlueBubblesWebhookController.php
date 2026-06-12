<?php

namespace App\Http\Controllers;

use App\Pai\Chat\GenericChannelReplyJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * iMessage via BlueBubbles：BB server 把新訊息 webhook 進來 →
 * 背景跑對話大腦 → 用 BB server API 回到同一個 chat。忽略自己發的訊息避免迴圈。
 */
class BlueBubblesWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->input('type') !== 'new-message') {
            return response()->json(['ok' => true]);
        }
        $data = (array) $request->input('data', []);
        if (! empty($data['isFromMe'])) {
            return response()->json(['ok' => true]); // 自己發的不回
        }
        $text = trim((string) ($data['text'] ?? ''));
        $chatGuid = (string) (data_get($data, 'chats.0.guid') ?? '');
        if ($text !== '' && $chatGuid !== '') {
            GenericChannelReplyJob::dispatch('bluebubbles', $chatGuid, $text, ['chat_guid' => $chatGuid]);
        }

        return response()->json(['ok' => true]);
    }
}
