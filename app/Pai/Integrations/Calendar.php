<?php

namespace App\Pai\Integrations;

use App\Pai\Settings\Settings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google 行事曆（唯讀，免 OAuth）：用使用者的「私人 iCal 網址（secret address in iCal format）」抓 .ics 解析。
 * 設定鍵 calendar.ics_url。建立事件走手機端 add_calendar_event（device 工具）。
 */
class Calendar
{
    public function __construct(private readonly Settings $settings) {}

    public function configured(): bool
    {
        return (bool) $this->settings->get('calendar.ics_url');
    }

    /** 取指定區間內的事件（依開始時間排序）。 */
    public function events(Carbon $from, Carbon $to): array
    {
        $url = (string) $this->settings->get('calendar.ics_url');
        if ($url === '') {
            return [];
        }
        try {
            $ics = Http::timeout(15)->get($url)->body();
        } catch (Throwable) {
            return [];
        }

        return $this->parse($ics, $from, $to);
    }

    public function today(): array
    {
        $tz = 'Asia/Taipei';

        return $this->events(now($tz)->startOfDay(), now($tz)->endOfDay());
    }

    public function upcoming(int $hours = 48): array
    {
        return $this->events(now('Asia/Taipei'), now('Asia/Taipei')->addHours($hours));
    }

    /** 解析 .ics 的 VEVENT（支援 DTSTART/DTEND、全天事件、跨行 folding）。 */
    private function parse(string $ics, Carbon $from, Carbon $to): array
    {
        // 還原 RFC5545 折行（行首空白＝接續上一行）
        $ics = preg_replace("/\r\n[ \t]/", '', $ics);
        $ics = str_replace("\r\n", "\n", (string) $ics);
        $out = [];
        if (! preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks)) {
            return [];
        }
        foreach ($blocks[1] as $b) {
            $summary = $this->field($b, 'SUMMARY');
            $loc = $this->field($b, 'LOCATION');
            $start = $this->parseDt($b, 'DTSTART');
            $end = $this->parseDt($b, 'DTEND');
            if (! $start) {
                continue;
            }
            if ($start->lt($from) || $start->gt($to)) {
                continue;
            }
            $out[] = [
                'summary' => $summary ?: '(無標題)',
                'location' => $loc,
                'start' => $start,
                'end' => $end,
                'all_day' => ! str_contains($this->rawLine($b, 'DTSTART'), 'T'),
            ];
        }
        usort($out, fn ($a, $b) => $a['start']->timestamp <=> $b['start']->timestamp);

        return $out;
    }

    private function field(string $block, string $key): string
    {
        if (preg_match('/^'.$key.'[^:\n]*:(.+)$/m', $block, $m)) {
            return trim(str_replace(['\\,', '\\n', '\\;'], [',', ' ', ';'], $m[1]));
        }

        return '';
    }

    private function rawLine(string $block, string $key): string
    {
        return preg_match('/^'.$key.'[^\n]*$/m', $block, $m) ? $m[0] : '';
    }

    private function parseDt(string $block, string $key): ?Carbon
    {
        if (! preg_match('/^'.$key.'([^:\n]*):([0-9TZ]+)$/m', $block, $m)) {
            return null;
        }
        $params = $m[1];
        $val = $m[2];
        try {
            if (preg_match('/TZID=([^;:]+)/', $params, $tz)) {
                return Carbon::createFromFormat('Ymd\THis', rtrim($val, 'Z'), $tz[1])->setTimezone('Asia/Taipei');
            }
            if (str_ends_with($val, 'Z')) {
                return Carbon::createFromFormat('Ymd\THis\Z', $val, 'UTC')->setTimezone('Asia/Taipei');
            }
            if (str_contains($val, 'T')) {
                return Carbon::createFromFormat('Ymd\THis', $val, 'Asia/Taipei');
            }

            return Carbon::createFromFormat('Ymd', $val, 'Asia/Taipei')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** 行事曆事件 → 一行可讀字串。 */
    public static function line(array $e): string
    {
        $t = $e['all_day'] ? '整天' : $e['start']->format('H:i');
        $loc = $e['location'] !== '' ? "（{$e['location']}）" : '';

        return "{$t} {$e['summary']}{$loc}";
    }
}
