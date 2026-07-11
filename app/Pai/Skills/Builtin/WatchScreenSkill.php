<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Skills\Skill;
use App\Pai\Watch\WatchTask;
use App\Pai\Watch\WatchTickJob;

/**
 * 視覺守望模式：「幫我盯著這個畫面/搶票頁/進度條，X 發生就叫我」。
 * 背景週期截手機畫面給視覺 LLM 判斷，命中就通知＋手機念出；到時限沒等到會自動收尾。
 */
class WatchScreenSkill implements Skill
{
    public function name(): string
    {
        return 'watch-screen';
    }

    public function description(): string
    {
        return '守望模式：持續盯著手機當前畫面，直到指定狀況發生就主動通知並念出來。'
            .'適合「幫我盯著搶票頁面開賣」「下載/轉檔好了叫我」「進度條跑完通知我」「畫面有變化就告訴我」。'
            .'goal 要寫清楚在等什麼狀況；使用者先把要盯的畫面留在手機前景。';
    }

    public function parameters(): array
    {
        return [
            'goal' => '要盯什麼＋發生什麼狀況要叫人（如「盯著拓元購票頁，出現可購買/開賣就叫我」）',
            'minutes' => '（選填）最多盯幾分鐘，預設 30、上限 120，到時沒等到會自動停',
            'interval_sec' => '（選填）幾秒看一次，預設 20、最小 10',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $uid = Tenant::id();
        if ($uid === null) {
            return '無法判斷帳號，請在登入情境下使用。';
        }
        $goal = trim((string) ($args['goal'] ?? ''));
        if ($goal === '') {
            return '要告訴我盯什麼、發生什麼狀況要叫你（goal）。';
        }
        if (WatchTask::where('user_id', $uid)->where('status', 'active')->count() >= 3) {
            return '同時最多盯 3 個畫面，先說「取消守望」停掉一些再來。';
        }
        $node = WatchTask::phoneNode($uid);
        if ($node === null) {
            return '找不到在線的手機節點，沒辦法盯畫面。請確認手機 App 有連上再試。';
        }
        $minutes = max(1, min(120, (int) ($args['minutes'] ?? 0) ?: 30));
        $interval = max(10, (int) ($args['interval_sec'] ?? 0) ?: 20);

        $w = WatchTask::create([
            'user_id' => $uid, 'node' => $node, 'goal' => $goal,
            'interval_sec' => $interval, 'expires_at' => now()->addMinutes($minutes),
        ]);
        WatchTickJob::dispatch($w->id, $w->issueTickToken());

        $warn = preg_match('/(撞|危險|危险|跌倒|摔|安全|小偷|火|瓦斯)/u', $goal)
            ? "\n⚠️ 老實說：我每一輪要好幾秒才判讀一次，秒級的碰撞/危險警示我來不及，請不要把人身安全交給我；適合進度條、開賣、有人出現這類幾十秒級的事。"
            : '';

        return "👀 開始守望（#{$w->id}）：{$goal}。我會每 {$interval} 秒看一次「{$node}」的畫面，"
            ."最多盯 {$minutes} 分鐘，看到就通知你＋用手機念出來。請把要盯的畫面留在手機前景；說「取消守望」可停止。{$warn}";
    }
}
