<?php

namespace App\Pai\Notify;

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
        ];
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
