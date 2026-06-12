<?php

namespace App\Pai\Commute;

use App\Models\User;
use App\Pai\Automation\Geo;
use App\Pai\Mcp\McpServer;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * 行程出發提醒：讀「手機行事曆」有地址的事件（免 API），到「該出發時刻＝開始時間−車程」就提醒，
 * 並可一鍵開導航。與通勤遲到模式同一套（Geo 車程估算 + 手機 TTS + 通知按鈕 + 語音回答）。
 */
class EventGuard
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Notifier $notifier,
        private readonly Geo $geo,
    ) {}

    /** 排程每幾分鐘呼叫：掃每個開啟此功能帳號的近期行程。 */
    public function tick(): void
    {
        foreach (User::all() as $user) {
            $uid = $user->id;
            if (! (bool) $this->settings->get('event_guard.enabled', false, $uid)) {
                continue;
            }
            try {
                $this->check($user);
            } catch (\Throwable) {
            }
        }
    }

    public function check(User $user): void
    {
        $uid = $user->id;
        $node = $this->ownerPhoneNode($uid);
        if ($node === null) {
            return;
        }
        // 讀手機行事曆（未來 1 天，JSON）
        $r = ReverseBus::call($node, 'calendar_read', ['days' => 1, 'json' => true], 30);
        $events = json_decode((string) ($r['text'] ?? '[]'), true);
        if (! is_array($events) || empty($events)) {
            return;
        }
        $lead = (int) ($this->settings->get('event_guard.lead_min', 180, $uid) ?: 180);
        $now = now('Asia/Taipei');
        $here = null;

        foreach ($events as $e) {
            $loc = trim((string) ($e['location'] ?? ''));
            if (! empty($e['all_day']) || $loc === '' || empty($e['begin'])) {
                continue;
            }
            $begin = \Carbon\Carbon::createFromTimestampMs((int) $e['begin'], 'Asia/Taipei');
            $minsToStart = $now->diffInMinutes($begin, false);
            if ($minsToStart < 0 || $minsToStart > $lead) {
                continue; // 已過或還太遠
            }
            $title = (string) ($e['title'] ?? '行程');
            $key = 'event:reminded:'.$uid.':'.md5($title.$e['begin']);
            // 先算車程才知道該出發沒（避免每分鐘都打 Geo：用 here 快取）
            $here ??= $this->geo->deviceLatLng($node);
            $there = $this->geo->resolve($loc);
            if ($here === null || $there === null) {
                continue;
            }
            $mins = $this->geo->driveMinutes($here, $there);
            if ($mins === null) {
                continue;
            }
            $leaveAt = $begin->copy()->subMinutes($mins);
            if ($now->lt($leaveAt)) {
                continue; // 還沒到該出發時刻
            }
            if (! Cache::add($key, 1, 86400)) {
                continue; // 這個行程提醒過了
            }

            $eta = $now->copy()->addMinutes($mins);
            $late = max(0, (int) $begin->diffInMinutes($eta, false));
            $km = number_format($this->geo->meters($here[0], $here[1], $there[0], $there[1]) / 1000, 1);
            $head = "🗓️ 該出發去「{$title}」了！{$begin->format('H:i')} 開始，地點在 {$km} 公里外，車程約 {$mins} 分鐘";
            // 會遲到 → 主動問「要不要傳訊息跟對方說會晚到」（像通勤）；準時 → 問要不要開導航
            if ($late > 0) {
                $q = "{$head}，預計遲到約 {$late} 分鐘。要我幫你傳訊息跟對方說會晚到嗎？（也可以開導航）";
                $actions = [
                    ['label' => '✉️ 傳訊息說會遲到', 'path' => '/api/event/decide', 'body' => ['decision' => 'notify']],
                    ['label' => '🗺️ 開導航', 'path' => '/api/event/decide', 'body' => ['decision' => 'map']],
                    ['label' => '✖ 不用', 'path' => '/api/event/decide', 'body' => ['decision' => 'skip']],
                ];
            } else {
                $q = "{$head}，現在出發來得及。要開導航嗎？";
                $actions = [
                    ['label' => '🗺️ 開導航', 'path' => '/api/event/decide', 'body' => ['decision' => 'map']],
                    ['label' => '✉️ 還是先跟對方說一聲', 'path' => '/api/event/decide', 'body' => ['decision' => 'notify']],
                    ['label' => '✖ 知道了', 'path' => '/api/event/decide', 'body' => ['decision' => 'skip']],
                ];
            }

            Cache::put("event:pending:{$uid}", ['dest' => $loc, 'title' => $title, 'late' => $late], 3600);
            Cache::put("voice:pendingq:{$uid}", ['kind' => 'event', 'late' => $late], 1800);
            try {
                ReverseBus::fire($node, 'voice_start', []);
                ReverseBus::fire($node, 'phone_speak', ['text' => $q.'可以直接跟我說「好」開導航，或點通知按鈕。']);
            } catch (\Throwable) {
            }
            $this->notifier->send($q, $actions);

            return; // 一次只提醒最近的一個行程
        }
    }

    /** 開導航到行程地點（按鈕/語音）。 */
    public function openMap(int $uid, string $preferNode = ''): string
    {
        $p = Cache::get("event:pending:{$uid}");
        $dest = is_array($p) ? (string) ($p['dest'] ?? '') : '';
        $node = $preferNode !== '' ? $preferNode : $this->ownerPhoneNode($uid);
        if ($dest === '' || $node === null) {
            return '沒有待出發的行程，或手機離線。';
        }
        try {
            $app = (string) $this->settings->get('commute.nav_app', '', $uid);
            ReverseBus::fire($node, 'maps_route', ['destination' => $dest, 'origin' => '', 'mode' => 'driving', 'app' => $app]);

            return "已開啟往「".(is_array($p) ? $p['title'] : '行程')."」的導航。";
        } catch (\Throwable $e) {
            return '開導航失敗：'.$e->getMessage();
        }
    }

    /** 通知對方會遲到：交給 agent（依行程/聯絡人判斷對象，不寫死主管）。 */
    public function notifyAttendee(int $uid, string $preferNode = ''): string
    {
        $p = Cache::get("event:pending:{$uid}");
        if (! is_array($p)) {
            return '沒有待通知的行程。';
        }
        $title = (string) ($p['title'] ?? '行程');
        $late = (int) ($p['late'] ?? 0);
        $user = User::find($uid);
        if ($user === null) {
            return '找不到帳號。';
        }
        $node = $preferNode !== '' ? $preferNode : $this->ownerPhoneNode($uid);
        $lateTxt = $late > 0 ? "大約會遲到 {$late} 分鐘" : '可能會晚一點到';
        $instr = "我等一下要去的行程「{$title}」{$lateTxt}。請幫我傳訊息通知這個行程的對方/與會者，說我會晚點到、抱歉。"
            ."如果你知道對方是誰（從聯絡人或行事曆）就直接用 LINE 傳；若不確定對方是誰，就先問我要傳給誰。";
        try {
            $conv = \App\Pai\Chat\Conversation::where('voice_sid', "event:{$uid}")->latest('id')->first()
                ?? \App\Pai\Chat\Conversation::create(['voice_sid' => "event:{$uid}", 'user_id' => $uid, 'title' => '行程提醒']);
            if ($node) {
                Cache::put("pai:device:{$conv->id}", $node, 3600);
            }
            $conv->addMessage('user', $instr, ['source' => 'event']);
            $r = app(\App\Pai\Chat\ChatResponder::class)->respond($conv, $instr);
            $reply = trim((string) ($r['reply'] ?? '')) ?: '我來幫你通知對方。';
            $conv->addMessage('assistant', $reply, ['source' => 'event']);

            return $reply;
        } catch (\Throwable $e) {
            return '通知時出了點問題：'.$e->getMessage();
        }
    }

    private function ownerPhoneNode(int $uid): ?string
    {
        try {
            $owned = McpServer::where('user_id', $uid)->where('url', 'like', 'reverse://%')->pluck('name')->all();
            $online = array_values(array_filter(ReverseBus::onlineNodes(), fn ($n) => in_array($n, $owned, true)));
            $phones = array_values(array_filter($online, fn ($n) => ! preg_match('/mac|macbook|imac|air|pc|desktop|windows|linux|laptop/i', $n)));

            return $phones[0] ?? $online[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
