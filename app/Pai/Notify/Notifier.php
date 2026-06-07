<?php

namespace App\Pai\Notify;

use App\Pai\Mcp\ReverseBus;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 多通道外部推播：Telegram / LINE / 通用 webhook（Slack/Discord 相容）。
 * 所有 token/目標皆從 Settings 讀取（DB 覆寫 config），故可在後台即時設定。
 */
class Notifier
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * 推送到所有「已設定」的通道，回傳每通道 bool（成功與否）。
     *
     * @return array<string, bool>
     */
    public function send(string $text): array
    {
        return array_map(fn ($r) => $r['ok'], $this->dispatch($text));
    }

    /**
     * 詳細推送結果，含是否已設定與錯誤原因。
     *
     * @return array<string, array{configured: bool, ok: bool, error: ?string}>
     */
    public function dispatch(string $text): array
    {
        return [
            'webhook' => $this->webhook($text),
            'telegram' => $this->telegram($text),
            'line' => $this->line($text),
            'phone' => $this->phone($text),
        ];
    }

    /** 推到所有在線的手機（Android）節點通知列（fire-and-forget，不阻塞）。 */
    private function phone(string $text): array
    {
        try {
            $nodes = ReverseBus::onlineNodes();
            if (empty($nodes)) {
                return ['configured' => false, 'ok' => false, 'error' => '無在線手機節點'];
            }
            foreach ($nodes as $n) {
                ReverseBus::fire($n, 'phone_notify', ['title' => 'PAI 通知', 'text' => mb_substr($text, 0, 500)]);
            }

            return ['configured' => true, 'ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['configured' => true, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, bool> */
    public function configured(): array
    {
        return [
            'webhook' => (bool) $this->settings->get('notify.webhook_url'),
            'telegram' => (bool) ($this->settings->get('notify.telegram.token') && $this->settings->get('notify.telegram.chat_id')),
            'line' => (bool) ($this->settings->get('notify.line.token') && $this->settings->get('notify.line.to')),
        ];
    }

    /**
     * 回覆到指定的 Telegram chat（雙向）。$quickReplies 給快速回覆鍵盤（點一下即送出該文字）。
     *
     * @param  list<string>  $quickReplies
     */
    public function sendTelegramTo(string $chatId, string $text, array $quickReplies = []): bool
    {
        $token = $this->settings->get('notify.telegram.token');
        if (! $token) {
            return false;
        }
        $payload = ['chat_id' => $chatId, 'text' => $text];
        if ($quickReplies !== []) {
            $payload['reply_markup'] = json_encode([
                'keyboard' => [array_map(fn ($t) => ['text' => $t], $quickReplies)],
                'one_time_keyboard' => true, 'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE);
        }

        return $this->call(fn () => Http::timeout(8)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload))['ok'];
    }

    /** Telegram「輸入中…」動畫（sendChatAction）。約 5 秒過期，生成期間需節流補發。 */
    public function sendTelegramTyping(string $chatId): bool
    {
        $token = $this->settings->get('notify.telegram.token');
        if (! $token) {
            return false;
        }

        return $this->call(fn () => Http::timeout(5)
            ->post("https://api.telegram.org/bot{$token}/sendChatAction", ['chat_id' => $chatId, 'action' => 'typing']))['ok'];
    }

    /** LINE 載入中動畫（僅 1:1 聊天有效；5–60 秒、5 的倍數；bot 回覆時自動消失）。 */
    public function sendLineLoading(string $to, int $seconds = 60): bool
    {
        $token = $this->settings->get('notify.line.token');
        if (! $token) {
            return false;
        }
        $seconds = (int) max(5, min(60, round($seconds / 5) * 5));

        return $this->call(fn () => Http::timeout(5)->withToken($token)
            ->post('https://api.line.me/v2/bot/chat/loading/start', ['chatId' => $to, 'loadingSeconds' => $seconds]))['ok'];
    }

    /**
     * 回覆/推送到指定的 LINE 對象（push API）。$quickReplies 給快速回覆鈕（點一下即送出該文字）。
     *
     * @param  list<string>  $quickReplies
     */
    public function sendLineTo(string $to, string $text, array $quickReplies = []): bool
    {
        $token = $this->settings->get('notify.line.token');
        if (! $token) {
            return false;
        }
        $msg = ['type' => 'text', 'text' => $text];
        if ($quickReplies !== []) {
            $msg['quickReply'] = ['items' => array_map(fn ($t) => [
                'type' => 'action', 'action' => ['type' => 'message', 'label' => mb_substr($t, 0, 20), 'text' => $t],
            ], $quickReplies)];
        }

        return $this->call(fn () => Http::timeout(8)->withToken($token)
            ->post('https://api.line.me/v2/bot/message/push', ['to' => $to, 'messages' => [$msg]]))['ok'];
    }

    /**
     * Telegram：對 bot 傳訊後可自動偵測使用者 chat id（getUpdates）。
     */
    public function detectTelegramChatId(): ?string
    {
        $token = $this->settings->get('notify.telegram.token');
        if (! $token) {
            return null;
        }
        try {
            $updates = Http::timeout(8)->get("https://api.telegram.org/bot{$token}/getUpdates")->json('result') ?? [];
            foreach (array_reverse($updates) as $u) {
                $id = data_get($u, 'message.chat.id') ?? data_get($u, 'message.from.id')
                    ?? data_get($u, 'edited_message.chat.id');
                if ($id) {
                    return (string) $id;
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    private function webhook(string $text): array
    {
        $url = $this->settings->get('notify.webhook_url');
        if (! $url) {
            return ['configured' => false, 'ok' => false, 'error' => null];
        }

        return $this->call(fn () => Http::timeout(5)->post($url, ['text' => $text]));
    }

    private function telegram(string $text): array
    {
        $token = $this->settings->get('notify.telegram.token');
        $chat = $this->settings->get('notify.telegram.chat_id');
        if (! $token || ! $chat) {
            return ['configured' => false, 'ok' => false, 'error' => null];
        }

        return $this->call(fn () => Http::timeout(8)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chat, 'text' => $text]));
    }

    private function line(string $text): array
    {
        $token = $this->settings->get('notify.line.token');
        $to = $this->settings->get('notify.line.to');
        if (! $token || ! $to) {
            return ['configured' => false, 'ok' => false, 'error' => null];
        }

        return $this->call(fn () => Http::timeout(8)->withToken($token)
            ->post('https://api.line.me/v2/bot/message/push', [
                'to' => $to, 'messages' => [['type' => 'text', 'text' => $text]],
            ]));
    }

    /** 執行一次推送並擷取成敗與錯誤訊息。 */
    private function call(callable $fn): array
    {
        try {
            $resp = $fn();
            if ($resp->successful()) {
                return ['configured' => true, 'ok' => true, 'error' => null];
            }

            return ['configured' => true, 'ok' => false, 'error' => $resp->json('description') ?? $resp->json('message') ?? ('HTTP '.$resp->status())];
        } catch (Throwable $e) {
            return ['configured' => true, 'ok' => false, 'error' => $e->getMessage()];
        }
    }
}
