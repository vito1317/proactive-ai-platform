<?php

namespace App\Http\Controllers;

use App\Pai\Chat\Conversation;
use App\Pai\Chat\TelegramReplyJob;
use App\Pai\Notify\ChannelRegistry;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram 接收 webhook（雙向）：使用者在 TG 對 bot 傳訊 → 這裡收到 →
 * 交給對話大腦處理並回覆。必須快速回 200（重活交 queue）。
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, Settings $settings, ChannelRegistry $channels, Notifier $notifier): JsonResponse
    {
        // 驗證來自 Telegram 的 secret token（setWebhook 時設定）
        $secret = $settings->get('notify.telegram.webhook_secret');
        if ($secret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            logger()->warning('TG webhook secret 不符（可能需重跑 pai:telegram-webhook set 重新同步）');

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
            // 外連 API 一律延到回應送出後執行（terminating）——webhook 必須立即回 200，
            // 否則 Telegram 會 "Read timeout expired" 進入重試退避，訊息延遲數分鐘。
            if (in_array(strtolower(strtok($text, '@')), ['/new', '/start'], true)) {
                // /new（/start 同義）：開新會話 session，舊上下文保留在後台
                Conversation::newSession('tg', (string) $chatId);
                app()->terminating(fn () => $notifier->sendTelegramTo((string) $chatId, '🆕 已開啟新的會話，上下文已重置。直接說話即可開始！'));
            } elseif ($text !== '') {
                TelegramReplyJob::dispatch((string) $chatId, $text);
                app()->terminating(fn () => $notifier->sendTelegramTyping((string) $chatId)); // 回應後立即顯示「輸入中…」
            }
        }

        return response()->json(['ok' => true]); // 立即回 200，避免 Telegram 重送
    }
}
