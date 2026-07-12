<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Skills\Skill;
use App\Pai\Watch\WatchTask;
use App\Pai\Watch\WatchTickJob;

/**
 * 網頁盯梢：「幫我盯這個商品降到 500 以下叫我」「這頁有貨/開賣通知我」。
 * 週期抓頁面文字給 LLM 判斷，命中就通知＋手機念出；頁面沒變不耗推理。
 */
class WatchWebSkill implements Skill
{
    public function name(): string
    {
        return 'watch-web';
    }

    public function description(): string
    {
        return '盯一個「網頁」直到條件成立就通知（降價到門檻/有貨/開賣/出現某資訊）。'
            .'url=要盯的網址（必填，沒有網址就先用其他工具找到再呼叫）；goal=等什麼條件（含數字門檻，如「價格降到500以下」）；'
            .'預設每 10 分鐘看一次、最多盯 24 小時。盯手機畫面用 watch-screen，這個是盯網頁。';
    }

    public function parameters(): array
    {
        return [
            'url' => '要盯的網址（http/https）',
            'goal' => '等什麼條件成立（例：價格降到 500 以下、顯示有貨、開放購買）',
            'interval_min' => '（選填）幾分鐘看一次，預設 10、最小 5',
            'hours' => '（選填）最多盯幾小時，預設 24、上限 168（7天）',
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
            return '無法判斷帳號。';
        }
        $url = trim((string) ($args['url'] ?? ''));
        if (! preg_match('#^https?://.+#i', $url)) {
            return 'url 不合法（要 http/https 完整網址）。還不知道網址就先查到再叫我盯。';
        }
        $goal = trim((string) ($args['goal'] ?? ''));
        if (mb_strlen($goal) < 4) {
            return '要告訴我等什麼條件（goal），例：價格降到 500 以下。';
        }
        if (WatchTask::where('user_id', $uid)->where('status', 'active')->where('source', 'like', 'web:%')->count() >= 5) {
            return '同時最多盯 5 個網頁，先說「取消守望」停掉一些。';
        }
        $intervalMin = max(5, (int) ($args['interval_min'] ?? 0) ?: 10);
        $hours = max(1, min(168, (int) ($args['hours'] ?? 0) ?: 24));

        $w = WatchTask::create([
            'user_id' => $uid, 'node' => WatchTask::phoneNode($uid),
            'source' => 'web:'.$url, 'goal' => $goal,
            'interval_sec' => $intervalMin * 60,
            'expires_at' => now()->addHours($hours),
        ]);
        WatchTickJob::dispatch($w->id, $w->issueTickToken());

        return "🕵️ 開始盯網頁（#{$w->id}）：{$goal}。每 {$intervalMin} 分鐘看一次、最多 {$hours} 小時，"
            .'條件成立就通知你＋手機念出；說「取消守望」可停止。'."\n{$url}";
    }
}
