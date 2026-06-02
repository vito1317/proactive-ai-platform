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
     * 推送文字到所有「已設定」的通道。
     *
     * @return array<string, bool> 通道 => 是否送出
     */
    public function send(string $text): array
    {
        return [
            'webhook' => $this->webhook($text),
            'telegram' => $this->telegram($text),
            'line' => $this->line($text),
        ];
    }

    /** 哪些通道已設定完成。 */
    public function configured(): array
    {
        return [
            'webhook' => (bool) $this->settings->get('notify.webhook_url'),
            'telegram' => (bool) ($this->settings->get('notify.telegram.token') && $this->settings->get('notify.telegram.chat_id')),
            'line' => (bool) ($this->settings->get('notify.line.token') && $this->settings->get('notify.line.to')),
        ];
    }

    private function webhook(string $text): bool
    {
        $url = $this->settings->get('notify.webhook_url');
        if (! $url) {
            return false;
        }

        return $this->try(fn () => Http::timeout(5)->post($url, ['text' => $text])->successful());
    }

    private function telegram(string $text): bool
    {
        $token = $this->settings->get('notify.telegram.token');
        $chat = $this->settings->get('notify.telegram.chat_id');
        if (! $token || ! $chat) {
            return false;
        }

        return $this->try(fn () => Http::timeout(5)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chat, 'text' => $text])
            ->successful());
    }

    private function line(string $text): bool
    {
        $token = $this->settings->get('notify.line.token');
        $to = $this->settings->get('notify.line.to');
        if (! $token || ! $to) {
            return false;
        }

        return $this->try(fn () => Http::timeout(5)->withToken($token)
            ->post('https://api.line.me/v2/bot/message/push', [
                'to' => $to,
                'messages' => [['type' => 'text', 'text' => $text]],
            ])->successful());
    }

    private function try(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (Throwable) {
            return false; // 推播失敗不影響主流程
        }
    }
}
