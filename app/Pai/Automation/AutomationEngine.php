<?php

namespace App\Pai\Automation;

use App\Models\User;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Mcp\McpServer;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * 通用自動化引擎：跑使用者（或 AI）建立的「觸發→條件→動作」流程。
 *
 * 觸發 trigger：
 *   {type:'daily', at:'HH:MM', days:[1..7]}        每天某時刻（可限上班日）
 *   {type:'interval', every_min:N}                 每 N 分鐘
 *   {type:'unlock', window:['07:00','09:30'], days} 早晨解鎖手機那刻
 * 條件 conditions[]（全部成立才繼續）：
 *   {type:'location_outside'|'location_inside', place:'公司或地址', radius_m:400}
 *   {type:'time_after', time:'HH:MM'} / {type:'weekday', days:[1..5]} / {type:'always'}
 * 動作 actions[]（依序執行）：
 *   {type:'speak', text}                手機 TTS 念
 *   {type:'notify', text}               推播通知
 *   {type:'agent', instruction}         交對話大腦執行（可傳 LINE/開 App/查資料…）
 *   {type:'open_map', place, app}       手機導航
 *   {type:'ask', question, yes:[...], no:[...]}  問使用者，按鈕(接受/拒絕)各自跑一串動作
 * 文字可用變數：{name}{km}{drive}{eta}{late}{time}{place}
 */
class AutomationEngine
{
    public function __construct(
        private readonly Geo $geo,
        private readonly Notifier $notifier,
        private readonly Settings $settings,
    ) {}

    /** 排程每分鐘呼叫：跑 daily / interval 觸發。 */
    public function tick(): void
    {
        $now = now('Asia/Taipei');
        foreach (Automation::where('enabled', true)->get() as $auto) {
            if ($this->autoStopIfDue($auto)) {
                continue; // 已到期/跑滿 → 本輪剛被自動停用
            }
            $t = (array) ($auto->spec['trigger'] ?? []);
            $fire = match ($t['type'] ?? '') {
                'daily' => $now->format('H:i') === ($t['at'] ?? '')
                    && (empty($t['days']) || in_array($now->isoWeekday(), (array) $t['days'], true)),
                'interval' => ((int) ($t['every_min'] ?? 0) > 0)
                    && ($now->hour * 60 + $now->minute) % (int) $t['every_min'] === 0,
                default => false,
            };
            if (! $fire) {
                continue;
            }
            $bucket = $now->format('Y-m-d H:i');
            if (! Cache::add("automation:fired:{$auto->id}:{$bucket}", 1, 3600)) {
                continue;
            }
            try {
                $this->evaluate($auto);
            } catch (\Throwable) {
            }
        }
    }

    /** 手機解鎖時呼叫：跑 unlock 觸發、在時間窗內、當天還沒跑過的流程。 */
    public function onUnlock(User $user): void
    {
        $now = now('Asia/Taipei');
        foreach (Automation::where('enabled', true)->where('user_id', $user->id)->get() as $auto) {
            if ($this->autoStopIfDue($auto)) {
                continue; // 已到期/跑滿 → 本輪剛被自動停用
            }
            $t = (array) ($auto->spec['trigger'] ?? []);
            if (($t['type'] ?? '') !== 'unlock') {
                continue;
            }
            if (! empty($t['days']) && ! in_array($now->isoWeekday(), (array) $t['days'], true)) {
                continue;
            }
            $win = (array) ($t['window'] ?? ['00:00', '23:59']);
            if ($now->format('H:i') < ($win[0] ?? '00:00') || $now->format('H:i') > ($win[1] ?? '23:59')) {
                continue;
            }
            if (! Cache::add("automation:fired:{$auto->id}:".$now->format('Y-m-d'), 1, 86400)) {
                continue;
            }
            try {
                $this->evaluate($auto);
            } catch (\Throwable) {
            }
        }
    }

    /** 評估條件，全過 → 跑動作。 */
    public function evaluate(Automation $auto): void
    {
        $uid = $auto->user_id;
        $node = $this->ownerPhoneNode($uid);
        $ctx = [
            'name' => trim((string) (User::find($uid)?->name ?? '')),
            'time' => now('Asia/Taipei')->format('H:i'),
        ];

        foreach ((array) ($auto->spec['conditions'] ?? []) as $cond) {
            if (! $this->checkCondition((array) $cond, $uid, $node, $ctx)) {
                return; // 任一條件不成立 → 不執行
            }
        }
        $this->runActions($uid, (array) ($auto->spec['actions'] ?? []), $ctx, $node, $auto);
        $this->recordRun($auto); // 真的執行了 → 計數，跑滿 max_runs 就自動停用
    }

    /**
     * 自動停止閘門：到期（expires_at 過了）或跑滿次數（run_count >= max_runs）→ 立刻停用。
     * 回 true 代表這條剛被停用、本輪不該再跑。AI 自建的臨時流程靠這個不會無限期殘留。
     */
    private function autoStopIfDue(Automation $auto): bool
    {
        if (! $auto->isAutoStopped()) {
            return false;
        }
        $auto->enabled = false;
        $auto->save();
        $reason = ($auto->expires_at !== null && $auto->expires_at->isPast()) ? '已到設定的截止時間' : '已達設定的執行次數上限';
        try {
            \App\Pai\Agent\Tenant::set($auto->user_id);
            $this->notifier->send("🛑 自動化「{$auto->name}」{$reason}，已自動停止。");
        } catch (\Throwable) {
        }

        return true;
    }

    /** 記一次成功執行；若到達 max_runs 就順手自動停用。 */
    private function recordRun(Automation $auto): void
    {
        try {
            $auto->run_count = (int) $auto->run_count + 1;
            $auto->last_run_at = now();
            if ($auto->max_runs !== null && $auto->run_count >= (int) $auto->max_runs) {
                $auto->enabled = false;
            }
            $auto->save();
        } catch (\Throwable) {
        }
    }

    /** @param  array<string,mixed>  $ctx  by-ref 累積變數 */
    private function checkCondition(array $cond, int $uid, ?string $node, array &$ctx): bool
    {
        switch ($cond['type'] ?? '') {
            case 'always':
                return true;
            case 'weekday':
                return in_array(now('Asia/Taipei')->isoWeekday(), (array) ($cond['days'] ?? []), true);
            case 'time_after':
                return now('Asia/Taipei')->format('H:i') >= (string) ($cond['time'] ?? '00:00');
            case 'location_inside':
            case 'location_outside':
                if ($node === null) {
                    return false;
                }
                $here = $this->geo->deviceLatLng($node);
                $there = $this->geo->resolve((string) ($cond['place'] ?? ''));
                if ($here === null || $there === null) {
                    return false;
                }
                $dist = $this->geo->meters($here[0], $here[1], $there[0], $there[1]);
                $radius = (int) ($cond['radius_m'] ?? 400);
                $mins = $this->geo->driveMinutes($here, $there);
                $ctx['km'] = number_format($dist / 1000, 1);
                $ctx['drive'] = $mins ?? '?';
                $ctx['place'] = (string) ($cond['place'] ?? '');
                if ($mins !== null) {
                    $eta = now('Asia/Taipei')->addMinutes($mins);
                    $ctx['eta'] = $eta->format('H:i');
                    $at = (string) ($cond['deadline'] ?? data_get($ctx, '_at', ''));
                    if (preg_match('/^\d{1,2}:\d{2}$/', $at)) {
                        [$h, $i] = explode(':', $at);
                        $ctx['late'] = max(0, (int) now('Asia/Taipei')->setTime((int) $h, (int) $i)->diffInMinutes($eta, false));
                    }
                }

                return ($cond['type'] === 'location_inside') ? ($dist <= $radius) : ($dist > $radius);
            default:
                return false;
        }
    }

    /** @param  array<int,mixed>  $actions */
    public function runActions(int $uid, array $actions, array $ctx, ?string $node, ?Automation $auto = null): void
    {
        foreach ($actions as $a) {
            $a = (array) $a;
            $type = $a['type'] ?? '';
            $text = $this->subst((string) ($a['text'] ?? $a['question'] ?? $a['instruction'] ?? ''), $ctx);
            switch ($type) {
                case 'speak':
                    if ($node) {
                        try {
                            ReverseBus::fire($node, 'phone_speak', ['text' => $text]);
                        } catch (\Throwable) {
                        }
                    }
                    break;
                case 'notify':
                    $this->notifier->send($text);
                    break;
                case 'open_map':
                    if ($node) {
                        try {
                            ReverseBus::fire($node, 'maps_route', [
                                'destination' => $this->subst((string) ($a['place'] ?? ''), $ctx),
                                'origin' => '', 'mode' => 'driving', 'app' => (string) ($a['app'] ?? ''),
                            ]);
                        } catch (\Throwable) {
                        }
                    }
                    break;
                case 'agent':
                    $this->runAgent($uid, $text, $node);
                    break;
                case 'ask':
                    $this->runAsk($uid, $text, $a, $ctx, $node, $auto);

                    return; // ask 之後的動作交給使用者按鈕決定的分支
            }
        }
    }

    /** 問使用者（手機通知接受/拒絕按鈕 + TTS 念）；分支動作暫存，按下後由 /api/automation/decide 執行。 */
    private function runAsk(int $uid, string $question, array $a, array $ctx, ?string $node, ?Automation $auto): void
    {
        $aid = $auto?->id ?? 0;
        Cache::put("automation:ask:{$uid}:{$aid}", [
            'yes' => $a['yes'] ?? [], 'no' => $a['no'] ?? [], 'ctx' => $ctx,
        ], 3600);
        // 待回答提問：讓使用者可用語音直接回「好/不用」
        Cache::put("voice:pendingq:{$uid}", ['kind' => 'automation', 'autoId' => $aid], 1800);
        $yesLabel = (string) ($a['yes_label'] ?? '✅ 好');
        $noLabel = (string) ($a['no_label'] ?? '✖ 不用');
        $actions = [
            ['label' => $yesLabel, 'path' => '/api/automation/decide', 'body' => ['id' => $aid, 'branch' => 'yes']],
            ['label' => $noLabel, 'path' => '/api/automation/decide', 'body' => ['id' => $aid, 'branch' => 'no']],
        ];
        if ($node) {
            try {
                ReverseBus::fire($node, 'voice_start', []); // 自動喚醒全雙工語音聆聽回答
                ReverseBus::fire($node, 'phone_speak', ['text' => $question]);
            } catch (\Throwable) {
            }
        }
        $this->notifier->send($question, $actions);
    }

    /** 按鈕被按下：跑 yes/no 分支的動作。 */
    public function decide(int $uid, int $autoId, string $branch, string $preferNode = ''): string
    {
        $p = Cache::pull("automation:ask:{$uid}:{$autoId}");
        if (! is_array($p)) {
            return '這個詢問已處理過或已過期。';
        }
        $actions = (array) ($p[$branch] ?? []);
        if (empty($actions)) {
            return $branch === 'no' ? '好，不執行。' : '好的。';
        }
        $node = $preferNode !== '' ? $preferNode : $this->ownerPhoneNode($uid);
        $this->runActions($uid, $actions, (array) ($p['ctx'] ?? []), $node);

        return '已執行你的選擇。';
    }

    /** 交對話大腦執行一句自然語言指令（可傳 LINE/開 App/查資料…，與你平常用法相同）。 */
    private function runAgent(int $uid, string $instruction, ?string $node = null): void
    {
        if (trim($instruction) === '') {
            return;
        }
        $node = $node ?: $this->ownerPhoneNode($uid);
        $report = '';
        try {
            $conv = Conversation::where('voice_sid', "automation:{$uid}")->latest('id')->first()
                ?? Conversation::create(['voice_sid' => "automation:{$uid}", 'user_id' => $uid, 'title' => '自動化流程']);
            if ($node) {
                Cache::put("pai:device:{$conv->id}", $node, 3600); // agent 在手機上操作
            }
            $conv->addMessage('user', $instruction, ['source' => 'automation']);
            $r = app(ChatResponder::class)->respond($conv, $instruction);
            $report = trim((string) ($r['reply'] ?? '')) ?: '我處理完了。';
            $conv->addMessage('assistant', $report, ['source' => 'automation']);
        } catch (\Throwable $e) {
            $report = '剛剛那件事處理時出了點問題：'.$e->getMessage();
        }
        // 不論成功失敗都用 agent TTS 念出回報
        if ($node) {
            try {
                ReverseBus::fire($node, 'phone_speak', ['text' => $report]);
            } catch (\Throwable) {
            }
        }
    }

    private function subst(string $s, array $ctx): string
    {
        foreach ($ctx as $k => $v) {
            if (! str_starts_with((string) $k, '_')) {
                $s = str_replace('{'.$k.'}', (string) $v, $s);
            }
        }

        return $s;
    }

    private function ownerPhoneNode(int $uid): ?string
    {
        try {
            $owned = McpServer::where('user_id', $uid)->where('url', 'like', 'reverse://%')->pluck('name')->all();
            $online = array_values(array_filter(ReverseBus::onlineNodes(), fn ($n) => in_array($n, $owned, true)));
            $phones = array_values(array_filter($online, fn ($n) => ! preg_match('/mac|macbook|imac|air|pc|desktop|windows|linux|laptop/i', $n)));

            return $phones[0] ?? $online[0] ?? null;
        } catch (\Throwable) {
        }

        return null;
    }
}
