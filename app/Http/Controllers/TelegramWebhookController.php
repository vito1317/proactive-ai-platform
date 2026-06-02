<?php

namespace App\Http\Controllers;

use App\Pai\Chat\TelegramReplyJob;
use App\Pai\Notify\ChannelRegistry;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram 接收 webhook（雙向）：使用者在 TG 對 bot 傳訊 → 這裡收到 →
 * 交給對話大腦處理並回覆。必須快速回 200（重活交 queue）。
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings, ChannelRegistry $channels): JsonResponse
    {
        // 驗證來自 Telegram 的 secret token（setWebhook 時設定）
        $secret = $settings->get('notify.telegram.webhook_secret');
        if ($secret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response()->json(['ok' => false], 403);
        }

        // 支援私訊(message) 與 頻道貼文(channel_post)
        $msg = $request->input('message') ?? $request->input('channel_post');
        $chat = $msg['chat'] ?? null;
        $chatId = $chat['id'] ?? null;
        $text = trim((string) ($msg['text'] ?? ''));

        if ($chatId) {
            // 登錄頻道供後台選取/查看
            $channels->remember('telegram', (string) $chatId, [
                'type' => $chat['type'] ?? 'private',
                'title' => $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['username'] ?? (string) $chatId),
            ]);
            if ($text !== '') {
                TelegramReplyJob::dispatch((string) $chatId, $text);
            }
        }

        return response()->json(['ok' => true]); // 立即回 200，避免 Telegram 重送
    }
}
