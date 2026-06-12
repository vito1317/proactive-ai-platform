<?php

namespace App\Pai\Commute;

use App\Models\User;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 早晨通勤守衛：上班時間到了若人還不在公司範圍 → 估車程 → 詢問是否幫忙傳訊息給主管說會遲到。
 *
 * 地理編碼用 Nominatim（OSM）、車程用 OSRM，皆免金鑰、端點可在設定覆寫成自架。
 * 「詢問」沿用 HITL 那套手機通知按鈕（接受/拒絕），按下打 /api/commute/decide。
 */
class CommuteGuard
{
    public function __construct(private readonly Settings $settings, private readonly Notifier $notifier) {}

    /** 排程每分鐘呼叫：判斷是否到了某帳號的上班時刻、是否該檢查。 */
    public function tick(): void
    {
        foreach (User::all() as $user) {
            $uid = $user->id;
            if (! (bool) $this->settings->get('commute.enabled', false, $uid)) {
                continue;
            }
            $start = trim((string) $this->settings->get('commute.work_start', '09:00', $uid));
            $now = now('Asia/Taipei');
            if ((int) $now->isoWeekday() > 5) {
                continue; // 只在工作日（一~五）
            }
            [$h, $i] = array_pad(explode(':', $start), 2, '0');
            $startAt = $now->copy()->setTime((int) $h, (int) $i);
            $date = $now->format('Y-m-d');
            // 監看窗：最多提前 lead_min 分鐘開始每分鐘檢查（預設 90）
            $leadCap = (int) ($this->settings->get('commute.lead_min', 90, $uid) ?: 90);
            $windowStart = $startAt->copy()->subMinutes($leadCap);

            if (Cache::has("commute:settled:{$uid}:{$date}")) {
                continue; // 今天已抵達，整天不再打擾
            }

            // pre：上班前的監看窗內，且「該出發」提醒還沒發過
            $inPre = $now->betweenIncluded($windowStart, $startAt->copy()->subMinute())
                && ! Cache::has("commute:checked:{$uid}:{$date}:pre");
            // at：剛好上班時刻
            $isAt = $now->format('H:i') === $start;
            if (! $inPre && ! $isAt) {
                continue;
            }
            try {
                $this->checkUser($user, $isAt ? 'at' : 'pre', $startAt);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * 對單一帳號做地點檢查 + 詢問。
     * $phase='pre'：監看窗內，只在「該出發時刻（上班時間−車程）」到了才提醒；'at'：上班時刻仍未到公司就提醒。
     */
    public function checkUser(User $user, string $phase = 'at', ?\Carbon\Carbon $startAt = null): void
    {
        $uid = $user->id;
        $node = $this->ownerPhoneNode($uid);
        if ($node === null) {
            return; // 手機不在線，無法定位
        }

        $loc = ReverseBus::call($node, 'device_location', [], 30);
        if (empty($loc['ok']) || ! preg_match('/緯度\s*(-?[\d.]+).*?經度\s*(-?[\d.]+)/u', (string) ($loc['text'] ?? ''), $m)) {
            return;
        }
        $here = [(float) $m[1], (float) $m[2]];

        $work = $this->resolvePlace((string) $this->settings->get('commute.work_place', '', $uid));
        if ($work === null) {
            return;
        }

        $now = now('Asia/Taipei');
        $date = $now->format('Y-m-d');
        $radius = (int) ($this->settings->get('commute.radius_m', 400, $uid) ?: 400);
        $dist = $this->haversine($here[0], $here[1], $work[0], $work[1]);
        if ($dist <= $radius) {
            Cache::put("commute:settled:{$uid}:{$date}", 1, 86400); // 已到公司，整天不再打擾

            return;
        }

        // 估車程（OSRM）
        $mins = $this->driveMinutes($here, $work);
        $start = trim((string) $this->settings->get('commute.work_start', '09:00', $uid));
        if ($startAt === null) {
            [$h, $i] = array_pad(explode(':', $start), 2, '0');
            $startAt = $now->copy()->setTime((int) $h, (int) $i);
        }

        if ($phase === 'pre') {
            // 提醒時間 = 上班時間 − 車程時間。還沒到「該出發」時刻就先不吵。
            if ($mins === null) {
                return; // 不知車程 → 等 at 階段
            }
            if ($now->lt($startAt->copy()->subMinutes($mins))) {
                return; // 還有餘裕，時間還沒到
            }
            if (! Cache::add("commute:checked:{$uid}:{$date}:pre", 1, 86400)) {
                return; // 該出發提醒已發過
            }
        } else { // at
            if (! Cache::add("commute:checked:{$uid}:{$date}:at", 1, 86400)) {
                return;
            }
        }

        // 預計到達 / 遲到分鐘
        $eta = $now->copy()->addMinutes($mins ?? 0);
        $late = $mins === null ? null : max(0, (int) $startAt->diffInMinutes($eta, false));
        $kmTxt = number_format($dist / 1000, 1);
        $driveTxt = $mins === null ? '未知' : "約 {$mins} 分鐘";
        $lateTxt = $late === null ? '' : ($late > 0 ? "，預計遲到約 {$late} 分鐘" : '，現在出發剛好趕得上');

        if ($phase === 'pre') {
            $head = "🚗 該出發了！上班時間 {$start}，你還在公司 {$kmTxt} 公里外，車程{$driveTxt}{$lateTxt}。";
        } else {
            $head = "🚗 上班時間到了，你還在公司 {$kmTxt} 公里外，車程{$driveTxt}{$lateTxt}。";
        }
        $question = $head.'要我幫你傳訊息跟主管說會晚到嗎？';

        // 暫存待辦（接受時才送），給按鈕用
        $managerVia = (string) $this->settings->get('commute.manager_via', 'line', $uid);
        $managerTo = (string) $this->settings->get('commute.manager_to', '', $uid);
        $tpl = (string) ($this->settings->get('commute.message_template', '', $uid)
            ?: '報告主管，我目前在通勤途中，預計約 {eta} 到，會晚到約 {late} 分鐘，抱歉造成不便！');
        $message = str_replace(['{late}', '{eta}'], [(string) ($late ?? '?'), $eta->format('H:i')], $tpl);

        Cache::put("commute:pending:{$uid}", [
            'via' => $managerVia, 'to' => $managerTo, 'message' => $message,
        ], 3600);

        $actions = [
            ['label' => '✅ 傳給主管', 'path' => '/api/commute/decide', 'body' => ['decision' => 'send']],
            ['label' => '🗺️ 開導航', 'path' => '/api/commute/decide', 'body' => ['decision' => 'map']],
            ['label' => '✖ 不用', 'path' => '/api/commute/decide', 'body' => ['decision' => 'skip']],
        ];
        // 記住公司地點，按「開導航」時用
        Cache::put("commute:dest:{$uid}", (string) $this->settings->get('commute.work_place', '', $uid), 3600);
        // 用說的：手機 TTS 念出來（不依賴全雙工語音是否開著）
        try {
            ReverseBus::fire($node, 'phone_speak', ['text' => $question.'可以的話點通知上的「傳給主管」。']);
        } catch (\Throwable) {
        }
        // 通知（含傳給主管/不用按鈕）
        $this->notifier->send($question, $actions);
    }

    /** 用手機 TTS 念一句給該帳號（決定後的口頭回饋）。 */
    public function speak(int $uid, string $text): void
    {
        $node = $this->ownerPhoneNode($uid);
        if ($node !== null) {
            try {
                ReverseBus::fire($node, 'phone_speak', ['text' => $text]);
            } catch (\Throwable) {
            }
        }
    }

    /** 在手機 Google 地圖開「目前位置 → 公司」的導航（開導航按鈕的後端）。 */
    public function openMap(int $uid): string
    {
        $dest = (string) (Cache::get("commute:dest:{$uid}") ?: $this->settings->get('commute.work_place', '', $uid));
        $node = $this->ownerPhoneNode($uid);
        if ($dest === '' || $node === null) {
            return '無法開導航（未設定公司地點或手機離線）。';
        }
        try {
            ReverseBus::fire($node, 'maps_route', ['destination' => $dest, 'origin' => '', 'mode' => 'driving']);

            return '已在手機開啟往公司的導航。';
        } catch (\Throwable $e) {
            return '開導航失敗：'.$e->getMessage();
        }
    }

    /** 接受後真的把訊息送給主管（接受按鈕的後端）。 */
    public function sendToManager(int $uid): string
    {
        $p = Cache::pull("commute:pending:{$uid}");
        if (! is_array($p) || trim((string) ($p['to'] ?? '')) === '') {
            return '沒有待發送的遲到訊息，或尚未設定主管聯絡方式。';
        }
        $to = (string) $p['to'];
        $text = (string) $p['message'];
        $via = (string) $p['via'];
        try {
            match ($via) {
                'telegram' => $this->sendTelegram($uid, $to, $text),
                'sms' => $this->sendSms($uid, $to, $text),
                default => $this->sendLine($uid, $to, $text),
            };

            return "已透過 {$via} 傳訊息給主管：{$text}";
        } catch (\Throwable $e) {
            return '傳送失敗：'.$e->getMessage();
        }
    }

    private function sendLine(int $uid, string $to, string $text): void
    {
        $token = (string) ($this->settings->own('notify.line.token', $uid) ?? $this->settings->get('notify.line.token'));
        Http::timeout(10)->withToken($token)->post('https://api.line.me/v2/bot/message/push', [
            'to' => $to, 'messages' => [['type' => 'text', 'text' => $text]],
        ])->throw();
    }

    private function sendTelegram(int $uid, string $to, string $text): void
    {
        $token = (string) ($this->settings->own('notify.telegram.token', $uid) ?? $this->settings->get('notify.telegram.token'));
        Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $to, 'text' => $text])->throw();
    }

    private function sendSms(int $uid, string $to, string $text): void
    {
        $sid = (string) $this->settings->get('twilio.account_sid');
        $tok = (string) $this->settings->get('twilio.auth_token');
        $from = (string) $this->settings->get('twilio.from');
        Http::timeout(12)->asForm()->withBasicAuth($sid, $tok)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", ['From' => $from, 'To' => $to, 'Body' => $text])
            ->throw();
    }

    /** 地址或「緯度,經度」→ [lat, lng]。 */
    private function resolvePlace(string $place): ?array
    {
        $place = trim($place);
        if ($place === '') {
            return null;
        }
        if (preg_match('/^\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*$/', $place, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }
        try {
            $base = rtrim((string) ($this->settings->get('commute.geocode_url') ?: 'https://nominatim.openstreetmap.org'), '/');
            $r = Http::timeout(12)->withHeaders(['User-Agent' => 'PAI-CommuteGuard/1.0'])
                ->get($base.'/search', ['q' => $place, 'format' => 'json', 'limit' => 1])->json();
            if (! empty($r[0]['lat'])) {
                return [(float) $r[0]['lat'], (float) $r[0]['lon']];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** OSRM 駕車車程（分鐘）；失敗回 null。 */
    private function driveMinutes(array $from, array $to): ?int
    {
        try {
            $base = rtrim((string) ($this->settings->get('commute.osrm_url') ?: 'https://router.project-osrm.org'), '/');
            $path = "{$from[1]},{$from[0]};{$to[1]},{$to[0]}"; // OSRM 是 lon,lat
            $r = Http::timeout(12)->get("{$base}/route/v1/driving/{$path}", ['overview' => 'false'])->json();
            $sec = data_get($r, 'routes.0.duration');

            return $sec === null ? null : (int) ceil($sec / 60);
        } catch (\Throwable) {
            return null;
        }
    }

    /** 兩點距離（公尺）。 */
    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function ownerPhoneNode(int $uid): ?string
    {
        try {
            $owned = \App\Pai\Mcp\McpServer::where('user_id', $uid)
                ->where('url', 'like', 'reverse://%')->pluck('name')->all();
            foreach (ReverseBus::onlineNodes() as $n) {
                if (in_array($n, $owned, true)) {
                    return $n;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
