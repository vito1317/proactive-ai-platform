<?php

namespace App\Pai\Governance;

use Illuminate\Support\Facades\Cache;

/**
 * 主動性治理閘（移植自 pai-framework 的 ProactivityPolicy）。
 *
 * 主動式 AI 的核心風險是「過度打擾」與「越權行動」：
 *  - gate()              決定一個動作最終被授予的自主等級（信心門檻、動作上限、回饋降級）
 *  - allowInterruption() 決定「現在能不能推播打擾人類」（安靜時段、干擾度公式、頻率上限）
 *
 * 與 framework 的刻意差異：安靜時段/打擾預算只閘「推播」、不閘「動作」——
 * 平台上把待核准動作降成 observe 等於讓資安處置無聲消失，那比打擾更危險；
 * 動作仍會留在中控台等核准，只是不往外推。
 *
 * 等級對應平台行為：
 *  3 ACT     → 自動執行（executed）
 *  2 ASK     → 待人類核准（awaiting_approval）
 *  1 SUGGEST → 只建議不執行（suggested）
 *  0 OBSERVE → 只記錄（observed）
 */
class ProactivityPolicy
{
    public const OBSERVE = 0;

    public const SUGGEST = 1;

    public const ASK = 2;

    public const ACT = 3;

    /** 最近一次 gate 的干擾成本（寫進 PAI Protocol 紀錄用）。 */
    public float $lastInterruptionCost = 0.0;

    /**
     * 治理閘：回傳最終授予等級與原因（可解釋性）。
     *
     * @param  int  $requested  既有自治規則（copilot/supervisor/autopilot + hitl_required）算出的等級
     * @return array{level: int, reason: string}
     */
    public function gate(string $domain, string $action, float $confidence, float $urgency, int $requested): array
    {
        $cfg = (array) config('pai.governance');
        $this->lastInterruptionCost = (float) ($cfg['interruption_cost'] ?? 0.0);

        if (! ($cfg['enabled'] ?? true)) {
            return ['level' => $requested, 'reason' => '治理層停用'];
        }

        // 1. 信心門檻：低信心一律只記錄
        if ($confidence < (float) ($cfg['min_confidence'] ?? 0.4)) {
            return ['level' => self::OBSERVE, 'reason' => sprintf('信心 %.2f 低於門檻', $confidence)];
        }

        // 2. 等級上限 = min(請求等級, 動作上限)
        $cap = (int) (($cfg['action_max_levels'][$action] ?? null) ?? ($cfg['default_max_level'] ?? self::ACT));
        $level = min($requested, $cap);
        $reason = $level < $requested ? "動作上限 {$cap}" : '依自治規則';

        // 自動執行需要更高信心
        if ($level === self::ACT && $confidence < (float) ($cfg['act_confidence'] ?? 0.85)) {
            $level = self::ASK;
            $reason = sprintf('信心 %.2f 未達自動執行門檻', $confidence);
        }

        // 3. 回饋調節：最近常被人類駁回的動作自動降一級（變保守）
        $threshold = (int) ($cfg['decline_penalty_threshold'] ?? 3);
        if ($threshold > 0 && $level > self::OBSERVE) {
            $declines = ActionFeedback::recentDeclines($domain, $action, (int) ($cfg['decline_window_days'] ?? 7));
            if ($declines >= $threshold) {
                $level--;
                $reason = "最近被駁回 {$declines} 次，自動降級";
            }
        }

        return ['level' => $level, 'reason' => $reason];
    }

    /**
     * 打擾治理：現在可以為這件事「推播」打擾人類嗎？
     * （不通過時動作仍保留在中控台，只是不往外推。）
     *
     * @return array{allowed: bool, reason: string}
     */
    public function allowInterruption(float $urgency, float $confidence): array
    {
        $cfg = (array) config('pai.governance');
        if (! ($cfg['enabled'] ?? true)) {
            return ['allowed' => true, 'reason' => '治理層停用'];
        }

        // 安靜時段（緊急度達 urgency_override 可突破）
        if ($this->inQuietHours((string) ($cfg['quiet_hours'] ?? '')) && $urgency < (float) ($cfg['urgency_override'] ?? 0.9)) {
            return ['allowed' => false, 'reason' => '安靜時段且非緊急'];
        }

        // PAI 干擾度公式：urgency × confidence 必須大於打擾成本
        $cost = (float) ($cfg['interruption_cost'] ?? 0.0);
        $this->lastInterruptionCost = $cost;
        if ($cost > 0 && $urgency * $confidence <= $cost) {
            return ['allowed' => false, 'reason' => sprintf('干擾度 %.2f×%.2f 未超過成本 %.2f', $urgency, $confidence, $cost)];
        }

        // 每小時打擾上限（0=不限制；以小時桶近似滑動視窗）
        $max = (int) ($cfg['max_interruptions_per_hour'] ?? 0);
        if ($max > 0) {
            $key = 'pai:gov:interrupts:'.now()->format('YmdH');
            $count = (int) Cache::get($key, 0);
            if ($count >= $max) {
                return ['allowed' => false, 'reason' => "本小時已打擾 {$count} 次，達上限"];
            }
            Cache::put($key, $count + 1, 7200);
        }

        return ['allowed' => true, 'reason' => 'OK'];
    }

    /** 記錄人類回饋（核准=正面、駁回=負面），供回饋調節。 */
    public function recordFeedback(string $domain, string $action, bool $positive): void
    {
        ActionFeedback::create(['domain' => $domain, 'action' => $action, 'positive' => $positive]);
    }

    /** '22-8' 格式：22:00–08:00（跨夜）；'13-15' 同日區間；空字串=停用。 */
    private function inQuietHours(string $spec): bool
    {
        if (! preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($spec), $m)) {
            return false;
        }
        [$start, $end] = [(int) $m[1], (int) $m[2]];
        $hour = (int) now()->format('G');

        return $start > $end ? ($hour >= $start || $hour < $end) : ($hour >= $start && $hour < $end);
    }
}
