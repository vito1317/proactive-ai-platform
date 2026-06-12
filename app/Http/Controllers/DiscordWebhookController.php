<?php

namespace App\Http\Controllers;

use App\Pai\Chat\DiscordReplyJob;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Discord Interactions 接收 webhook（雙向）：使用者在 Discord 用斜線指令 /ask <問題> →
 * Discord POST 到這裡 → 先回 type 5（延遲，3 秒內必回）→ 背景跑對話大腦 → 編輯原訊息回真結果。
 * 以 Discord Public Key 驗證 Ed25519 簽章。
 */
class DiscordWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings): JsonResponse
    {
        $publicKey = (string) $settings->get('discord.public_key', '');
        $sig = (string) $request->header('X-Signature-Ed25519', '');
        $ts = (string) $request->header('X-Signature-Timestamp', '');
        $body = $request->getContent();

        if ($publicKey === '' || $sig === '' || $ts === '' || ! $this->verify($publicKey, $sig, $ts, $body)) {
            return response()->json(['error' => 'bad signature'], 401);
        }

        $type = (int) $request->input('type');
        if ($type === 1) {
            return response()->json(['type' => 1]); // PING → PONG
        }
        if ($type === 2) { // APPLICATION_COMMAND
            $name = (string) $request->input('data.name');
            if ($name === 'ask' || $name === 'pai') {
                $question = '';
                foreach ((array) $request->input('data.options', []) as $opt) {
                    if (in_array(($opt['name'] ?? ''), ['question', 'q', 'message', '問題'], true)) {
                        $question = trim((string) ($opt['value'] ?? ''));
                    }
                }
                $channelId = (string) ($request->input('channel_id') ?? $request->input('channel.id') ?? 'dm');
                $token = (string) $request->input('token');
                if ($question !== '') {
                    // 背景處理 + 之後編輯原訊息（Discord 要求 3 秒內回應 → 先回延遲）
                    DiscordReplyJob::dispatch($channelId, $question, $token);
                }

                return response()->json(['type' => 5]); // DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE（顯示「思考中…」）
            }
        }

        return response()->json(['type' => 4, 'data' => ['content' => '不支援的互動。請用 /ask 問我問題。']]);
    }

    private function verify(string $publicKeyHex, string $sigHex, string $ts, string $body): bool
    {
        try {
            return sodium_crypto_sign_verify_detached(
                sodium_hex2bin($sigHex),
                $ts.$body,
                sodium_hex2bin($publicKeyHex),
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
