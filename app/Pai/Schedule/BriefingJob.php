<?php

namespace App\Pai\Schedule;

use App\Pai\Integrations\Calendar;
use App\Pai\Integrations\Mailer;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 晨間主動簡報：天氣 + 今日行事曆 + 未讀信摘要，組成一則推給使用者（手機通知 / 語音 / TG）。
 * 由排程器在 briefing.time（預設 08:00）每天觸發；也可語音「報今天概況」即時叫。
 */
class BriefingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function handle(Settings $settings, Calendar $cal, Mailer $mail, Notifier $notifier): void
    {
        $text = self::build($settings, $cal, $mail);
        try {
            $notifier->dispatch($text);
        } catch (Throwable) {
        }
        // 同步推語音節點念出來（若在線）
        try {
            foreach (\App\Pai\Mcp\ReverseBus::onlineNodes() as $n) {
                \App\Pai\Mcp\ReverseBus::fire($n, 'show_document', ['title' => '今日簡報', 'content' => $text]);
            }
        } catch (Throwable) {
        }
    }

    /** 組裝簡報文字（也給「報今天概況」即時用）。 */
    public static function build(Settings $settings, Calendar $cal, Mailer $mail): string
    {
        $now = now('Asia/Taipei');
        $w = ['日', '一', '二', '三', '四', '五', '六'][$now->dayOfWeek];
        $lines = ["☀️ 早安！今天是 {$now->format('n月j日')}（週{$w}）"];

        // 天氣（用設定的所在地，預設台北）
        $place = (string) ($settings->get('briefing.place') ?: '台北');
        $weather = self::weather($place);
        if ($weather !== '') {
            $lines[] = "\n🌤 天氣（{$place}）：{$weather}";
        }

        // 今日行事曆
        if ($cal->configured()) {
            $events = $cal->today();
            if ($events) {
                $lines[] = "\n📅 今日行程：";
                foreach ($events as $e) {
                    $lines[] = '・'.Calendar::line($e);
                }
            } else {
                $lines[] = "\n📅 今天沒有行程。";
            }
        }

        // 未讀信
        if ($mail->configured()) {
            $u = $mail->unread(5);
            if ($u['ok'] ?? false) {
                $cnt = $u['count'] ?? 0;
                if ($cnt > 0) {
                    $lines[] = "\n📧 未讀信 {$cnt} 封：";
                    foreach ($u['items'] as $it) {
                        $lines[] = "・{$it['from']}：{$it['subject']}";
                    }
                } else {
                    $lines[] = "\n📧 沒有未讀信。";
                }
            }
        }

        return implode("\n", $lines);
    }

    private static function weather(string $place): string
    {
        try {
            $g = Http::timeout(8)->get('https://geocoding-api.open-meteo.com/v1/search', ['name' => $place, 'count' => 1, 'language' => 'zh'])->json();
            $lat = $g['results'][0]['latitude'] ?? null;
            $lng = $g['results'][0]['longitude'] ?? null;
            if ($lat === null) {
                return '';
            }
            $f = Http::timeout(8)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $lat, 'longitude' => $lng, 'timezone' => 'Asia/Taipei',
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                'forecast_days' => 1,
            ])->json();
            $d = $f['daily'] ?? [];
            $hi = $d['temperature_2m_max'][0] ?? null;
            $lo = $d['temperature_2m_min'][0] ?? null;
            $pop = $d['precipitation_probability_max'][0] ?? null;
            if ($hi === null) {
                return '';
            }

            return "{$lo}~{$hi}°C，降雨機率 {$pop}%".(($pop ?? 0) >= 50 ? '（記得帶傘☔）' : '');
        } catch (Throwable) {
            return '';
        }
    }
}
