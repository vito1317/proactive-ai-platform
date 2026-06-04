<?php

namespace App\Pai\Notify;

use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 記錄/列出 bot 曾接觸過的 TG / LINE 頻道（對話/群組/個人），
 * 供後台「選取、查看」目前要推播的頻道。
 *
 * 來源：(1) webhook 收到訊息時自動登錄；(2) Telegram 可再用 getUpdates 主動刷新。
 */
class ChannelRegistry
{
    public function __construct(private readonly Settings $settings) {}

    /** 登錄一個曾接觸的頻道（依 id 去重、更新最後出現時間）。 */
    public function remember(string $platform, string $id, array $meta = []): void
    {
        $all = $this->keyed($platform);
        $all[$id] = array_merge($all[$id] ?? [], ['id' => $id], $meta);
        $this->settings->set("notify.{$platform}.channels", array_values($all));
    }

    /** @return list<array<string,mixed>> 已知頻道，含 selected 標記 */
    public function list(string $platform): array
    {
        $selectedKey = $platform === 'telegram' ? 'notify.telegram.chat_id' : 'notify.line.to';
        $selected = (string) ($this->settings->get($selectedKey) ?? '');

        return array_map(fn ($c) => [...$c, 'selected' => ((string) $c['id']) === $selected && $selected !== ''],
            array_values($this->keyed($platform)));
    }

    /** 設定某平台目前要推播的頻道。 */
    public function select(string $platform, string $id): void
    {
        $this->settings->set($platform === 'telegram' ? 'notify.telegram.chat_id' : 'notify.line.to', $id);
    }

    /** Telegram：用 getUpdates 主動撈最近對話過的頻道並登錄。回傳新增數。 */
    public function refreshTelegram(): int
    {
        $token = $this->settings->get('notify.telegram.token');
        if (! $token) {
            return 0;
        }
        $before = count($this->keyed('telegram'));
        try {
            $updates = Http::timeout(8)->get("https://api.telegram.org/bot{$token}/getUpdates")->json('result') ?? [];
            foreach ($updates as $u) {
                $chat = data_get($u, 'message.chat') ?? data_get($u, 'edited_message.chat') ?? data_get($u, 'channel_post.chat');
                if ($chat && isset($chat['id'])) {
                    $this->remember('telegram', (string) $chat['id'], [
                        'type' => $chat['type'] ?? 'private',
                        'title' => $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['username'] ?? (string) $chat['id']),
                    ]);
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return count($this->keyed('telegram')) - $before;
    }

    /** @return array<string,array<string,mixed>> id => channel */
    private function keyed(string $platform): array
    {
        $out = [];
        foreach ((array) $this->settings->get("notify.{$platform}.channels", []) as $c) {
            if (is_array($c) && isset($c['id'])) {
                $out[(string) $c['id']] = $c;
            }
        }

        return $out;
    }
}
