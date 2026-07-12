<?php

namespace App\Pai\Notify;

use App\Pai\Cognition\LlmClient;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 通知分流中心：手機把收到的 App 通知轉送上來，AI 分三級——
 *   urgent：立刻推播＋手機念出（真人找你、時效性、異常警示）
 *   normal：累積成每小時一則摘要（不逐則吵你）
 *   noise ：廣告/行銷/遊戲誘導 → 靜音只計數
 * 同 App+標題 15 分鐘內重複 → 沿用上次分級不再問 LLM；黑名單 App 直接靜音。
 */
class NotificationTriage
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Notifier $notifier,
        private readonly Settings $settings,
    ) {}

    public function handle(int $uid, string $app, string $title, string $text): string
    {
        \App\Pai\Agent\Tenant::set($uid);
        // 黑名單 App（設定：逗號分隔）直接靜音
        $muted = array_filter(array_map('trim', explode(',', (string) $this->settings->get('notify_triage.muted_apps', '', $uid))));
        foreach ($muted as $mApp) {
            if ($mApp !== '' && mb_stripos($app, $mApp) !== false) {
                $this->count($uid, 'noise');

                return 'muted_app';
            }
        }
        // 重複通知（同 App+標題 15 分鐘窗）→ 沿用上次分級，省 LLM
        $dupKey = 'ntf:dup:'.$uid.':'.md5($app.'|'.$title);
        $class = (string) (Cache::get($dupKey) ?? '');
        if ($class === '') {
            $class = $this->classify($app, $title, $text);
            Cache::put($dupKey, $class, 900);
        }
        $this->count($uid, $class);

        if ($class === 'urgent') {
            $msg = "🔔 重要通知｜{$app}：{$title}".($text !== '' ? "\n{$text}" : '');
            try {
                $this->notifier->send($msg);
            } catch (Throwable) {
            }
            if (($node = ReverseBus::ownerPhoneNode($uid)) !== null) {
                try {
                    ReverseBus::fire($node, 'phone_speak', ['text' => "重要通知，{$app}：{$title}。".mb_substr($text, 0, 80)]);
                } catch (Throwable) {
                }
            }

            return 'urgent';
        }
        if ($class === 'noise') {
            return 'noise';
        }
        // normal → 進小時摘要
        $key = "ntf:digest:{$uid}";
        $list = (array) Cache::get($key, []);
        $list[] = "・{$app}｜{$title}".($text !== '' ? '：'.mb_substr($text, 0, 60) : '');
        Cache::put($key, array_slice($list, -30), 7200);

        return 'digested';
    }

    private function classify(string $app, string $title, string $text): string
    {
        try {
            $v = $this->llm->chatJson([
                ['role' => 'system', 'content' => '你是通知分流員，幫使用者把手機通知分級，輸出 JSON：{"class":"urgent|normal|noise"}。'
                    .'urgent=真人在找他（訊息/來電未接/主管客戶家人）、時效性（取貨最後期限/班機異動/帳戶異常/OTP）；'
                    .'noise=廣告、行銷、促銷、遊戲誘導、社群按讚/推薦、新聞推播；'
                    .'normal=其他有資訊價值但不急的（出貨通知、帳單產生、行事曆、天氣）。只輸出 JSON。'],
                ['role' => 'user', 'content' => "App：{$app}\n標題：{$title}\n內容：".mb_substr($text, 0, 300)],
            ], ['max_tokens' => 60]);
            $c = (string) ($v['class'] ?? 'normal');

            return in_array($c, ['urgent', 'normal', 'noise'], true) ? $c : 'normal';
        } catch (Throwable) {
            return 'normal'; // LLM 掛了寧可進摘要，不漏掉
        }
    }

    private function count(int $uid, string $class): void
    {
        $key = "ntf:count:{$uid}:{$class}:".now('Asia/Taipei')->format('Y-m-d');
        Cache::add($key, 0, 86400 * 8);
        Cache::increment($key);
    }

    /** 排程每小時：把 normal 通知榨成一則摘要（附今日靜音統計）。 */
    public function flushDigests(): void
    {
        foreach (\App\Models\User::pluck('id') as $uid) {
            $list = Cache::pull("ntf:digest:{$uid}");
            if (! is_array($list) || $list === []) {
                continue;
            }
            $noise = (int) Cache::get("ntf:count:{$uid}:noise:".now('Asia/Taipei')->format('Y-m-d'), 0);
            try {
                \App\Pai\Agent\Tenant::set((int) $uid);
                $this->notifier->send("🔕 過去一小時的一般通知（".count($list)." 則）：\n".implode("\n", $list)
                    .($noise > 0 ? "\n（今天另有 {$noise} 則廣告/雜訊已幫你靜音）" : ''));
            } catch (Throwable) {
            }
        }
    }
}
