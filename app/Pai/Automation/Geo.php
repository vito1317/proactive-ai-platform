<?php

namespace App\Pai\Automation;

use App\Pai\Mcp\ReverseBus;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Http;

/**
 * 地理工具：地址→座標(Nominatim)、兩點駕車車程(OSRM)、距離(Haversine)、手機目前定位。
 * 皆免金鑰、端點可在設定覆寫成自架。通勤守衛與自動化引擎共用。
 */
class Geo
{
    public function __construct(private readonly Settings $settings) {}

    /** 手機目前定位 [lat, lng]；失敗回 null。 */
    public function deviceLatLng(string $node): ?array
    {
        $loc = ReverseBus::call($node, 'device_location', [], 30);
        if (! empty($loc['ok']) && preg_match('/緯度\s*(-?[\d.]+).*?經度\s*(-?[\d.]+)/u', (string) ($loc['text'] ?? ''), $m)) {
            return [(float) $m[1], (float) $m[2]];
        }

        return null;
    }

    /**
     * 地址或「緯度,經度」→ [lat, lng]。
     * 台灣門牌 Nominatim 常查不到 → 多級退化：原字串 → 路名+區+市（去門牌/樓層）。
     */
    public function resolve(string $place): ?array
    {
        $place = trim($place);
        if ($place === '') {
            return null;
        }
        if (preg_match('/^\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*$/', $place, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }
        foreach ($this->geocodeCandidates($place) as $q) {
            $hit = $this->geocodeOnce($q);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** 由精到粗的查詢字串：完整地址 → 路名(+段)+區+市。 */
    private function geocodeCandidates(string $place): array
    {
        $cands = [$place];
        $city = '';
        $district = '';
        $road = '';
        if (preg_match('/([^\s,，]{1,3}[縣市])/u', $place, $m)) {
            $city = $m[1];
        }
        if (preg_match('/([^\s,，]{1,4}(?:區|鄉|鎮|市區))/u', $place, $m)) {
            $district = $m[1];
        }
        if (preg_match('/([^\s,，]{1,10}(?:路|街|大道)(?:[一二三四五六七八九十\d]+段)?)/u', $place, $m)) {
            $road = $m[1];
        }
        if ($road !== '') {
            $cands[] = trim("{$road} {$district} {$city}");
        }
        if ($district !== '') {
            $cands[] = trim("{$district} {$city}");
        }

        return array_values(array_unique(array_filter($cands)));
    }

    private function geocodeOnce(string $q): ?array
    {
        try {
            $base = rtrim((string) ($this->settings->get('commute.geocode_url') ?: 'https://nominatim.openstreetmap.org'), '/');
            $r = Http::timeout(15)->withHeaders(['User-Agent' => 'PAI-Automation/1.0'])
                ->withOptions(['force_ip_resolve' => 'v4']) // 修 PHP-FPM IPv6 DNS 解析逾時
                ->get($base.'/search', ['q' => $q, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'tw'])->json();
            if (! empty($r[0]['lat'])) {
                return [(float) $r[0]['lat'], (float) $r[0]['lon']];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** OSRM 駕車車程（分鐘）；失敗回 null。 */
    public function driveMinutes(array $from, array $to): ?int
    {
        try {
            $base = rtrim((string) ($this->settings->get('commute.osrm_url') ?: 'https://router.project-osrm.org'), '/');
            $path = "{$from[1]},{$from[0]};{$to[1]},{$to[0]}"; // OSRM 是 lon,lat
            $sec = data_get(Http::timeout(15)->withOptions(['force_ip_resolve' => 'v4'])
                ->get("{$base}/route/v1/driving/{$path}", ['overview' => 'false'])->json(), 'routes.0.duration');

            return $sec === null ? null : (int) ceil($sec / 60);
        } catch (\Throwable) {
            return null;
        }
    }

    /** 兩點距離（公尺）。 */
    public function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
