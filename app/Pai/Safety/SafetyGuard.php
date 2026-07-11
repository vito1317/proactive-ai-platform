<?php

namespace App\Pai\Safety;

use App\Pai\Automation\AutomationEngine;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 安全守護：手機端傳感器哨兵（撞擊/跌倒偵測）的伺服器側流程。
 * 手機本地偵測到衝擊會「先」震動＋念「你還好嗎」（即時反應不等雲端），再回報這裡：
 *   impact/fall → 建立升級倒數（預設 60s）＋通知按鈕〔我沒事/需要幫忙〕＋語音待答
 *     ・使用者說「我沒事」/按鈕 → 解除
 *     ・說「救命/需要幫忙」/按鈕/倒數到期沒回應 → 通知緊急聯絡（含定位連結），
 *       有設 safety.emergency_instruction 就交給 agent 執行（如：用LINE傳訊息給媽媽）
 *   collision_warning → 只記錄（本地已即時警示，雲端不重複吵人）
 */
class SafetyGuard
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly Settings $settings,
    ) {}

    private function key(int $uid): string
    {
        return "safety:pending:{$uid}";
    }

    /** 手機回報事件入口。回傳給 App 的簡短說明。 */
    public function handleEvent(int $uid, array $p): string
    {
        \App\Pai\Agent\Tenant::set($uid);
        $type = (string) ($p['type'] ?? '');
        if ($type === 'collision_warning') {
            // 本地已即時嗶聲警示；這裡只累計，讓「AI 週報/中控台」看得到
            Cache::increment('safety:collision_warns:'.now('Asia/Taipei')->format('Y-m-d'));

            return 'recorded';
        }
        if (! in_array($type, ['impact', 'fall'], true)) {
            return 'ignored';
        }
        if (! (bool) $this->settings->get('safety.enabled', true, $uid)) {
            return 'disabled';
        }
        if (Cache::get($this->key($uid)) !== null) {
            return 'already_pending'; // 已有進行中的確認，不重複疊加
        }

        $label = $type === 'fall' ? '疑似跌倒' : '劇烈撞擊';
        $waitSec = max(20, (int) $this->settings->get('safety.no_response_sec', 60, $uid));
        Cache::put($this->key($uid), [
            'type' => $type,
            'magnitude' => (float) ($p['magnitude'] ?? 0),
            'lat' => $p['lat'] ?? null,
            'lng' => $p['lng'] ?? null,
            'at' => now()->toIso8601String(),
        ], 1800);

        // 語音待答：說「我沒事 / 需要幫忙」即可決定
        Cache::put("voice:pendingq:{$uid}", ['kind' => 'safety'], 900);

        $node = ReverseBus::ownerPhoneNode($uid);
        if ($node !== null) {
            try {
                ReverseBus::fire($node, 'voice_start', []); // 喚醒全雙工聆聽「我沒事/需要幫忙」
                if (empty($p['spoken'])) { // 手機端已本地念過就不重複念
                    ReverseBus::fire($node, 'phone_speak', [
                        'text' => "偵測到{$label}，你還好嗎？跟我說「我沒事」就解除；{$waitSec} 秒內沒回應我會通知你的緊急聯絡人。",
                    ]);
                }
            } catch (Throwable) {
            }
        }
        try {
            $this->notifier->send("🚨 偵測到{$label}（強度 ".number_format((float) ($p['magnitude'] ?? 0), 1)."g）。你還好嗎？{$waitSec} 秒內沒回應會自動求援。", [
                ['label' => '✅ 我沒事', 'path' => '/api/sensor/decide', 'body' => ['decision' => 'ok']],
                ['label' => '🆘 需要幫忙', 'path' => '/api/sensor/decide', 'body' => ['decision' => 'help']],
            ]);
        } catch (Throwable) {
        }
        SafetyEscalateJob::dispatch($uid)->delay(now()->addSeconds($waitSec));
        Log::info('SafetyGuard 事件', ['uid' => $uid, 'type' => $type, 'p' => $p]);

        return 'confirming';
    }

    /** 使用者回應（語音或通知按鈕）：ok=解除、help=立刻求援。回覆給使用者聽的話。 */
    public function resolve(int $uid, bool $ok): string
    {
        $p = Cache::pull($this->key($uid));
        Cache::forget("voice:pendingq:{$uid}");
        if ($p === null) {
            return '目前沒有待確認的安全警報。';
        }
        if ($ok) {
            return '好，解除警報了。注意安全！';
        }
        $this->escalate($uid, (array) $p, '使用者回覆需要幫忙');

        return '收到，我立刻通知你的緊急聯絡人並附上你的位置。撐住！';
    }

    /** 倒數到期沒回應（由 SafetyEscalateJob 呼叫）。 */
    public function escalateIfStillPending(int $uid): void
    {
        $p = Cache::pull($this->key($uid));
        if ($p === null) {
            return; // 已解除
        }
        Cache::forget("voice:pendingq:{$uid}");
        $this->escalate($uid, (array) $p, '倒數結束仍無回應');
    }

    private function escalate(int $uid, array $p, string $why): void
    {
        \App\Pai\Agent\Tenant::set($uid);
        $name = trim((string) (\App\Models\User::find($uid)?->name ?? '')) ?: '使用者';
        $label = ($p['type'] ?? '') === 'fall' ? '疑似跌倒' : '劇烈撞擊';
        $loc = ($p['lat'] ?? null) !== null && ($p['lng'] ?? null) !== null
            ? "位置：https://maps.google.com/?q={$p['lat']},{$p['lng']}"
            : '（沒有取到定位）';
        $msg = "🆘 {$name} 的手機偵測到{$label}且{$why}。{$loc}（時間 ".($p['at'] ?? '').'）';

        try {
            $this->notifier->send($msg);
        } catch (Throwable) {
        }
        // 交給 agent 執行使用者設定的求援動作（如：用 LINE 傳訊息給媽媽）
        $instr = trim((string) $this->settings->get('safety.emergency_instruction', '', $uid));
        if ($instr !== '') {
            try {
                app(AutomationEngine::class)->runActions($uid, [[
                    'type' => 'agent',
                    'instruction' => $instr."。附上狀況：{$msg}",
                ]], [], ReverseBus::ownerPhoneNode($uid));
            } catch (Throwable $e) {
                Log::warning('SafetyGuard 求援 agent 失敗', ['uid' => $uid, 'error' => $e->getMessage()]);
            }
        }
    }
}
