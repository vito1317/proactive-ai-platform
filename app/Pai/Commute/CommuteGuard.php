<?php

namespace App\Pai\Commute;

use App\Models\User;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Memory\UserMemory;
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
    public function __construct(
        private readonly Settings $settings,
        private readonly Notifier $notifier,
        private readonly \App\Pai\Automation\Geo $geo,
    ) {}

    /** 排程每分鐘呼叫：判斷是否到了某帳號的上班時刻、是否該檢查。 */
    public function tick(): void
    {
        foreach (User::all() as $user) {
            $uid = $user->id;
            if (! (bool) $this->settings->get('commute.enabled', false, $uid)) {
                continue;
            }
            $now = now('Asia/Taipei');
            if (! in_array($now->isoWeekday(), $this->workDays($uid), true)) {
                continue; // 非上班日
            }
            $start = $this->workStart($uid);
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
     * 解鎖手機觸發：你一醒來/開手機那刻立刻檢查（時間輪詢可能你還沒醒就發了沒用）。
     * 只在早晨窗內、當天還沒抵達、且 3 分鐘內沒查過時才做。
     */
    public function wake(User $user): void
    {
        $uid = $user->id;
        if (! (bool) $this->settings->get('commute.enabled', false, $uid)) {
            return;
        }
        $now = now('Asia/Taipei');
        if (! in_array($now->isoWeekday(), $this->workDays($uid), true)) {
            return;
        }
        $start = $this->workStart($uid);
        [$h, $i] = array_pad(explode(':', $start), 2, '0');
        $startAt = $now->copy()->setTime((int) $h, (int) $i);
        $leadCap = (int) ($this->settings->get('commute.lead_min', 90, $uid) ?: 90);
        // 早晨窗：上班前 leadCap 分鐘 ~ 上班後 60 分鐘
        if ($now->lt($startAt->copy()->subMinutes($leadCap)) || $now->gt($startAt->copy()->addMinutes(60))) {
            return;
        }
        $date = $now->format('Y-m-d');
        if (Cache::has("commute:settled:{$uid}:{$date}")) {
            return;
        }
        // 連續解鎖防抖：3 分鐘內只查一次定位
        if (! Cache::add("commute:wakelock:{$uid}", 1, 180)) {
            return;
        }
        try {
            $this->checkUser($user, $now->gte($startAt) ? 'at' : 'wake', $startAt);
        } catch (\Throwable) {
        }
    }

    /**
     * 對單一帳號做地點檢查 + 詢問。
     * $phase='wake'：剛解鎖→醒來簡報或（已過該出發時刻）該出發提醒；
     * 'pre'：監看窗內，只在「上班時間−車程」到了才提醒；'at'：上班時刻仍未到公司就提醒。
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

        $placeStr = $this->workPlaceStr($uid);
        $work = $placeStr === '' ? null : $this->geo->resolve($placeStr);
        if ($work === null) {
            return;
        }

        $now = now('Asia/Taipei');
        $date = $now->format('Y-m-d');
        $radius = (int) ($this->settings->get('commute.radius_m', 400, $uid) ?: 400);
        $dist = $this->geo->meters($here[0], $here[1], $work[0], $work[1]);
        if ($dist <= $radius) {
            Cache::put("commute:settled:{$uid}:{$date}", 1, 86400); // 已到公司，整天不再打擾

            return;
        }

        // 估車程（OSRM；用當下定位即時算，不需設定/記憶）
        $mins = $this->geo->driveMinutes($here, $work);
        $start = $this->workStart($uid);
        if ($startAt === null) {
            [$h, $i] = array_pad(explode(':', $start), 2, '0');
            $startAt = $now->copy()->setTime((int) $h, (int) $i);
        }
        $kmTxt = number_format($dist / 1000, 1);
        $leaveAt = $mins === null ? null : $startAt->copy()->subMinutes($mins); // 該出發時刻 = 上班 − 車程

        // wake：剛解鎖手機 → 若已過該出發時刻就當作「該出發」提醒；否則給一次「醒來簡報」
        if ($phase === 'wake') {
            if ($leaveAt !== null && $now->gte($leaveAt)) {
                $phase = 'pre';
            } else {
                if (! Cache::add("commute:checked:{$uid}:{$date}:wake", 1, 86400)) {
                    return; // 今天醒來簡報已給過
                }
                $leaveTxt = $leaveAt ? '，建議 '.$leaveAt->format('H:i').' 出發' : '';
                $name = trim((string) ($user->name ?? ''));
                $hi = $name !== '' ? "早安，{$name}！" : '早安！';
                $brief = "🚗 {$hi}今天 {$start} 上班，你在公司 {$kmTxt} 公里外，車程約 ".($mins ?? '?')." 分鐘{$leaveTxt}。到該出發時我會再提醒你。";
                try {
                    ReverseBus::fire($node, 'phone_speak', ['text' => $brief]);
                } catch (\Throwable) {
                }
                $this->notifier->send($brief);

                return;
            }
        }

        if ($phase === 'pre') {
            // 提醒時間 = 上班時間 − 車程時間。還沒到「該出發」時刻就先不吵。
            if ($mins === null) {
                return; // 不知車程 → 等 at 階段
            }
            if ($now->lt($leaveAt)) {
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
            ?: '報告{manager}，我目前在通勤途中，預計約 {eta} 到，會晚到約 {late} 分鐘，抱歉造成不便！');
        $message = str_replace(['{manager}', '{late}', '{eta}'], [$this->managerName($uid), (string) ($late ?? '?'), $eta->format('H:i')], $tpl);

        Cache::put("commute:pending:{$uid}", [
            'via' => $managerVia, 'to' => $managerTo, 'manager' => $this->managerName($uid), 'message' => $message,
        ], 3600);

        $actions = [
            ['label' => '✅ 傳給主管', 'path' => '/api/commute/decide', 'body' => ['decision' => 'send']],
            ['label' => '🗺️ 開導航', 'path' => '/api/commute/decide', 'body' => ['decision' => 'map']],
            ['label' => '✖ 不用', 'path' => '/api/commute/decide', 'body' => ['decision' => 'skip']],
        ];
        // 記住公司地點，按「開導航」時用
        Cache::put("commute:dest:{$uid}", $placeStr, 3600);
        // 待回答提問：讓使用者可用語音直接回「好/不用」
        Cache::put("voice:pendingq:{$uid}", ['kind' => 'commute'], 1800);
        try {
            ReverseBus::fire($node, 'voice_start', []); // 自動喚醒全雙工語音聆聽你的回答
            ReverseBus::fire($node, 'phone_speak', ['text' => $question.'可以的話直接跟我說「好」或「不用」，或點通知上的按鈕。']);
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
        $dest = (string) (Cache::get("commute:dest:{$uid}") ?: $this->workPlaceStr($uid));
        $node = $this->ownerPhoneNode($uid);
        if ($dest === '' || $node === null) {
            return '無法開導航（未設定公司地點或手機離線）。';
        }
        try {
            $app = (string) $this->settings->get('commute.nav_app', '', $uid);
            ReverseBus::fire($node, 'maps_route', ['destination' => $dest, 'origin' => '', 'mode' => 'driving', 'app' => $app]);

            return '已在手機開啟往公司的導航。';
        } catch (\Throwable $e) {
            return '開導航失敗：'.$e->getMessage();
        }
    }

    /** 接受後把訊息送給主管。有設明確收件 ID→直接 API 送；否則走 agent（用主管姓名自動發送，像你平常叫它傳 LINE 給某人）。 */
    public function sendToManager(int $uid, string $preferNode = ''): string
    {
        $p = Cache::pull("commute:pending:{$uid}");
        if (! is_array($p)) {
            return '沒有待發送的遲到訊息。';
        }
        $to = trim((string) ($p['to'] ?? ''));
        $text = (string) $p['message'];
        $via = (string) ($p['via'] ?: 'line');
        $manager = (string) ($p['manager'] ?? '主管');

        // 有明確收件 ID（LINE userId / TG chat_id / 手機號碼）→ 直接 API 送
        if ($to !== '') {
            try {
                match ($via) {
                    'telegram' => $this->sendTelegram($uid, $to, $text),
                    'sms' => $this->sendSms($uid, $to, $text),
                    default => $this->sendLine($uid, $to, $text),
                };

                return "已透過 {$via} 傳訊息給{$manager}：{$text}";
            } catch (\Throwable $e) {
                return '傳送失敗：'.$e->getMessage();
            }
        }

        // 沒設 ID → 交給 agent，用主管姓名自動發送（agent 會操作手機 LINE 找到人傳）
        $user = User::find($uid);
        if ($user === null) {
            return '找不到帳號，無法傳送。';
        }
        $verb = match ($via) {
            'sms' => '用簡訊',
            'telegram' => '用 Telegram',
            default => '用 LINE',
        };
        try {
            $conv = Conversation::where('voice_sid', "commute:{$uid}")->latest('id')->first()
                ?? Conversation::create(['voice_sid' => "commute:{$uid}", 'user_id' => $uid, 'title' => '通勤遲到通知']);
            // 把這個對話的執行節點指到「按按鈕的那台手機」，agent 才會在手機上開 LINE 操作
            $node = $preferNode !== '' ? $preferNode : $this->ownerPhoneNode($uid);
            if ($node) {
                Cache::put("pai:device:{$conv->id}", $node, 3600);
            }
            $conv->addMessage('user', "（通勤助手）請幫我{$verb}傳訊息給「{$manager}」，內容就是：{$text}", ['source' => 'commute']);
            $r = app(ChatResponder::class)->respond($conv, "請幫我{$verb}傳訊息給「{$manager}」，內容就是：{$text}");
            $reply = trim((string) ($r['reply'] ?? ''));
            $conv->addMessage('assistant', $reply, ['source' => 'commute']);

            // 完成後念出 agent 的實際結果（沒有就用預設）
            return $reply !== '' ? $reply : "已請 AI {$verb}傳給{$manager}：{$text}";
        } catch (\Throwable $e) {
            return '請 AI 傳送時失敗：'.$e->getMessage();
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

    /** 公司地點：設定優先，沒填→從長期記憶找（你只要跟 AI 說「我公司在…」即可）。 */
    private function workPlaceStr(int $uid): string
    {
        $p = trim((string) $this->settings->get('commute.work_place', '', $uid));

        return $p !== '' ? $p : ($this->placeFromMemory($uid) ?? '');
    }

    private function placeFromMemory(int $uid): ?string
    {
        try {
            $rows = UserMemory::where('user_id', $uid)
                ->where(fn ($q) => $q->where('content', 'like', '%公司%')
                    ->orWhere('content', 'like', '%辦公室%')->orWhere('content', 'like', '%上班地點%')->orWhere('content', 'like', '%公司地址%'))
                ->get();
            foreach ($rows as $r) {
                $c = (string) $r->content;
                if (preg_match('/(市|縣|区|區|鄉|鎮|路|街|號|号|巷|弄|段|大道)/u', $c)) {
                    // 去掉「我公司在/地址是」之類前綴，留地址讓 Nominatim 命中率高
                    return trim((string) preg_replace('/^.*?(在|位於|地址[:：是]?|是)/u', '', $c)) ?: $c;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** 主管稱呼：設定優先 → 長期記憶（如「我主管叫王經理」）→ 「主管」。 */
    private function managerName(int $uid): string
    {
        $n = trim((string) $this->settings->get('commute.manager_name', '', $uid));
        if ($n !== '') {
            return $n;
        }
        try {
            foreach (UserMemory::where('user_id', $uid)
                ->where(fn ($q) => $q->where('content', 'like', '%主管%')->orWhere('content', 'like', '%經理%')->orWhere('content', 'like', '%老闆%'))
                ->get() as $r) {
                // 「主管叫王經理」「我的主管是李大明」「老闆 陳總」「主管是 Rex Chang」（中英文皆可）
                if (preg_match('/(?:主管|經理|老闆|上司|主任)\s*(?:叫做|叫|是|為|名字|：|:)?\s*([A-Za-z][A-Za-z .]{1,24}[A-Za-z]|[\x{4e00}-\x{9fff}]{2,5}(?:經理|總|主任|協理|副總|課長|組長)?)/u', (string) $r->content, $m)) {
                    return trim($m[1]);
                }
            }
        } catch (\Throwable) {
        }

        return '主管';
    }

    /** 上班日：設定（如 1,2,3,4,5）優先 → 長期記憶（如「週一到週五上班」）→ 預設週一~週五。回 ISO 日(1=一…7=日)。 */
    private function workDays(int $uid): array
    {
        $d = $this->parseDays(trim((string) $this->settings->get('commute.work_days', '', $uid)), true);
        if ($d) {
            return $d;
        }
        try {
            foreach (UserMemory::where('user_id', $uid)->where('content', 'like', '%上班%')->get() as $r) {
                $d = $this->parseDays((string) $r->content, false);
                if ($d) {
                    return $d;
                }
            }
        } catch (\Throwable) {
        }

        return [1, 2, 3, 4, 5];
    }

    /** @return int[] ISO 日清單 */
    private function parseDays(string $s, bool $allowNumeric): array
    {
        if ($s === '') {
            return [];
        }
        if ($allowNumeric && preg_match('/^\s*[1-7](\s*,\s*[1-7])*\s*$/', $s)) {
            return array_values(array_unique(array_map('intval', preg_split('/\s*,\s*/', trim($s)))));
        }
        $map = ['一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '日' => 7, '天' => 7];
        // 範圍：週一到週五 / 禮拜一~五
        if (preg_match('/(?:週|周|禮拜|拜|星期)\s*([一二三四五六日天])\s*(?:到|至|~|-|－|—)\s*(?:週|周|禮拜|拜|星期)?\s*([一二三四五六日天])/u', $s, $m)) {
            $a = $map[$m[1]];
            $b = $map[$m[2]];
            if ($a <= $b) {
                return range($a, $b);
            }
        }
        // 列舉：週一、週三、週五
        if (preg_match_all('/(?:週|周|禮拜|拜|星期)\s*([一二三四五六日天])/u', $s, $mm) && $mm[1]) {
            return array_values(array_unique(array_map(fn ($c) => $map[$c], $mm[1])));
        }

        return [];
    }

    /** 上班時間：設定優先，沒填→從長期記憶解析（如「我九點上班」）；都沒有→09:00。 */
    private function workStart(int $uid): string
    {
        $s = trim((string) $this->settings->get('commute.work_start', '', $uid));
        if (preg_match('/^\d{1,2}:\d{2}$/', $s)) {
            return $s;
        }

        return $this->workStartFromMemory($uid) ?? '09:00';
    }

    private function workStartFromMemory(int $uid): ?string
    {
        try {
            $rows = UserMemory::where('user_id', $uid)->where('content', 'like', '%上班%')->get();
            foreach ($rows as $r) {
                $t = $this->parseTime((string) $r->content);
                if ($t !== null) {
                    return $t;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** 從一句話抽出時間 → HH:MM（支援 9:00 / 九點 / 早上九點半 / 下午…）。 */
    private function parseTime(string $c): ?string
    {
        if (preg_match('/(\d{1,2})[:：](\d{2})/u', $c, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        $cn = ['零' => 0, '一' => 1, '二' => 2, '兩' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10, '十一' => 11, '十二' => 12];
        if (preg_match('/([0-9]{1,2}|十一|十二|[一二兩三四五六七八九十])\s*點(半)?/u', $c, $m)) {
            $h = is_numeric($m[1]) ? (int) $m[1] : ($cn[$m[1]] ?? null);
            if ($h !== null) {
                $min = ! empty($m[2]) ? 30 : 0;
                if (preg_match('/(下午|晚上|傍晚|pm)/iu', $c) && $h < 12) {
                    $h += 12;
                }

                return sprintf('%02d:%02d', $h, $min);
            }
        }

        return null;
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
            $online = array_values(array_filter(ReverseBus::onlineNodes(), fn ($n) => in_array($n, $owned, true)));
            // 偏好「手機」節點（排除 Mac/PC/桌機名），開 LINE 等操作要在手機上
            $desktop = '/mac|macbook|imac|air|pc|desktop|windows|linux|laptop/i';
            $phones = array_values(array_filter($online, fn ($n) => ! preg_match($desktop, $n)));

            return $phones[0] ?? $online[0] ?? null;
        } catch (\Throwable) {
        }

        return null;
    }
}
