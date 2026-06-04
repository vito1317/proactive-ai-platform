<?php

namespace App\Console\Commands;

use App\Pai\Settings\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 設定/查詢 Telegram 接收 webhook（雙向）。
 * 用法：
 *   php artisan pai:telegram-webhook set    # 設定 webhook（自動產生 secret）
 *   php artisan pai:telegram-webhook info   # 查詢狀態
 *   php artisan pai:telegram-webhook delete # 移除
 */
class PaiTelegramWebhookCommand extends Command
{
    protected $signature = 'pai:telegram-webhook {action=set} {--url=}';

    protected $description = '設定 Telegram 接收 webhook（讓 bot 能回覆訊息）';

    public function handle(Settings $settings): int
    {
        $token = $settings->get('notify.telegram.token');
        if (! $token) {
            $this->error('尚未設定 Telegram token（後台或對話設定）。');

            return self::FAILURE;
        }
        $api = "https://api.telegram.org/bot{$token}";

        return match ($this->argument('action')) {
            'delete' => $this->out(Http::get("{$api}/deleteWebhook")->json()),
            'info' => $this->out(Http::get("{$api}/getWebhookInfo")->json()),
            default => $this->set($settings, $api),
        };
    }

    private function set(Settings $settings, string $api): int
    {
        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/webhooks/telegram';
        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram 要求 https webhook，目前 URL：{$url}（請設定 APP_URL 或 --url）");

            return self::FAILURE;
        }
        $secret = Str::random(40);
        $settings->set('notify.telegram.webhook_secret', $secret);

        $res = Http::post("{$api}/setWebhook", [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
        ])->json();

        $this->line("webhook URL：{$url}");

        return $this->out($res);
    }

    private function out(array $res): int
    {
        $this->line(json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($res['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
