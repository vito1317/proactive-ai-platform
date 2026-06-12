<?php

namespace App\Pai\Automation;

use App\Models\User;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Memory\UserMemory;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * 主動思考大腦：AI「無時無刻自己想」——排程定期觸發，讓 agent 根據時間＋使用者長期記憶＋
 * 已建立的自動化，自己判斷現在有沒有該主動做的事（提醒上班/行程、建立自動化、貼心提醒）。
 *
 * 用 agent 既有工具行動（create-automation / phone_notify / phone_speak / 實際幫忙），
 * 多數時候應「什麼都不做」(NOOP) 以免打擾。預設關閉，per-account 開啟。
 */
class ProactiveBrain
{
    public function __construct(private readonly Settings $settings) {}

    /** 排程每幾分鐘呼叫；對每個開啟主動思考的帳號，依其節奏觸發一次思考。 */
    public function tick(): void
    {
        $now = now('Asia/Taipei');
        foreach (User::all() as $user) {
            $uid = $user->id;
            if (! (bool) $this->settings->get('proactive.enabled', false, $uid)) {
                continue;
            }
            // 安靜時段（預設 22:00–07:00）不主動打擾
            $quiet = (string) ($this->settings->get('proactive.quiet', '22:00-07:00', $uid) ?: '');
            if ($this->inQuiet($now->format('H:i'), $quiet)) {
                continue;
            }
            $every = max(5, (int) ($this->settings->get('proactive.every_min', 30, $uid) ?: 30));
            if (! Cache::add("proactive:ran:{$uid}", 1, $every * 60)) {
                continue; // 還沒到下一次思考時間
            }
            try {
                $this->think($user);
            } catch (\Throwable) {
            }
        }
    }

    /** 觸發一次主動思考：把情境餵給 agent，讓它自己決定要不要行動。 */
    public function think(User $user): void
    {
        $uid = $user->id;
        $now = now('Asia/Taipei');
        $mem = UserMemory::where('user_id', $uid)->orderByDesc('pinned')->orderByDesc('id')->limit(40)->get()
            ->map(fn ($m) => '・'.$m->content)->implode("\n") ?: '（沒有記憶）';
        $autos = Automation::where('user_id', $uid)->get()
            ->map(fn ($a) => "#{$a->id} {$a->name}".($a->enabled ? '' : '（已停用）'))->implode("\n") ?: '（還沒有自動化）';

        $prompt = <<<TXT
（這是你的「主動思考」時刻：系統每隔一段時間自動喚醒你，使用者現在並沒有在跟你說話。）
現在時間：{$now->format('Y-m-d H:i')}（星期{$now->isoWeekday()}）。

你的任務：根據下面關於使用者的長期記憶與已建立的自動化，判斷「此刻」有沒有什麼你該主動為他做的事——
例如：快到上班/行程時間提醒、發現他有例行需求但還沒建自動化就主動建一條、或一句貼心而有用的提醒。

原則（很重要）：
- 多數時候應該「什麼都不做」。只有在明確有幫助、且現在這個時間點剛好需要、又不會打擾時才行動。
- 不要重複提醒已經提醒過或已有自動化在處理的事。
- 若沒事可做：只回覆「NOOP」，不要呼叫任何工具、不要發任何通知。
- 若要行動：用你的工具（create-automation 建立自動化、phone_notify 發通知、phone_speak 念出、或實際幫忙）。
  例如使用者記憶顯示固定上班時間但沒有通勤提醒自動化 → 可主動用 create-automation 建一條。

【使用者長期記憶】
{$mem}

【已建立的自動化】
{$autos}
TXT;

        try {
            $conv = Conversation::where('voice_sid', "proactive:{$uid}")->latest('id')->first()
                ?? Conversation::create(['voice_sid' => "proactive:{$uid}", 'user_id' => $uid, 'title' => '主動思考']);
            $r = app(ChatResponder::class)->respond($conv, $prompt);
            $reply = trim((string) ($r['reply'] ?? ''));
            $acted = $reply !== '' && ! str_contains(mb_strtoupper($reply), 'NOOP');
            // 每次思考都留一筆（供「思考記錄」頁顯示）：有行動記內容，沒事記 NOOP
            $conv->addMessage('assistant', $acted ? $reply : 'NOOP（這次沒事可做）', [
                'source' => 'proactive', 'acted' => $acted, 'at' => $now->format('Y-m-d H:i'),
            ]);
        } catch (\Throwable) {
        }
    }

    private function inQuiet(string $hm, string $range): bool
    {
        if (! preg_match('/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', trim($range), $m)) {
            return false;
        }
        [$a, $b] = [$m[1], $m[2]];

        return $a <= $b ? ($hm >= $a && $hm < $b) : ($hm >= $a || $hm < $b); // 跨午夜
    }
}
