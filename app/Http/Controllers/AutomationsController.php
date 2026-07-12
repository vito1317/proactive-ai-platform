<?php

namespace App\Http\Controllers;

use App\Pai\Automation\Automation;
use App\Pai\Chat\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 自動化流程 + AI 主動思考記錄的檢視/管理（web Inertia 頁 + 手機 JSON API 共用同一份資料）。
 * 認證：web 走 session；手機走 gateway device token（X-Register-Secret）。
 */
class AutomationsController extends Controller
{
    /** web 頁面（Inertia） */
    public function page(Request $request): Response
    {
        return Inertia::render('Automations', $this->gather((int) $request->user()->id));
    }

    /** 手機/原生端 JSON */
    public function data(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        if ($uid === null) {
            return response()->json(['error' => '未授權'], 403);
        }

        return response()->json($this->gather($uid));
    }

    /** 開關內建自動化（通勤/行程/主動思考，存 per-account 設定）。 */
    public function builtin(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        if ($uid === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $allowed = ['commute.enabled', 'event_guard.enabled', 'proactive.enabled'];
        $key = (string) $request->input('key', '');
        if (! in_array($key, $allowed, true)) {
            return response()->json(['error' => '不允許'], 422);
        }
        $val = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOL);
        app(\App\Pai\Settings\Settings::class)->set($key, $val, $uid);

        return response()->json(['ok' => true, 'enabled' => $val]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $uid = $this->uid($request);
        $auto = Automation::where('user_id', $uid)->find($id);
        if ($auto === null) {
            return response()->json(['error' => '找不到'], 404);
        }
        $action = (string) $request->input('action', 'toggle');
        if ($action === 'delete') {
            $auto->delete();

            return response()->json(['ok' => true, 'deleted' => true]);
        }
        // 設定/清除自動停止條件（截止時間 + 次數上限）
        if ($action === 'set_limit') {
            $auto->expires_at = Automation::parseExpiry($request->input('expires_at'));
            $auto->max_runs = Automation::parseMaxRuns($request->input('max_runs'));
            // 重新設了上限且尚未跑滿 → 順手把因到期/跑滿而停的流程重新啟用
            if (! $auto->isAutoStopped() && ($request->boolean('reenable', true))) {
                $auto->enabled = true;
            }
            $auto->save();

            return response()->json(['ok' => true, 'auto_stop' => $auto->autoStopLabel(), 'enabled' => $auto->enabled]);
        }
        $auto->enabled = $action === 'enable' ? true : ($action === 'disable' ? false : ! $auto->enabled);
        $auto->save();

        return response()->json(['ok' => true, 'enabled' => $auto->enabled]);
    }

    /**
     * 明天預演（dry-run 時間軸）：把未來 24 小時會發生的事排成時間軸——
     * enabled 自動化（daily/interval/unlock）＋定時任務＋晨間簡報＋進行中的守望。
     * 建完自動化立刻看到「明天 7:30 會發生什麼」，也能揪出互相打架的規則。
     */
    public function preview(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        if ($uid === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $now = now('Asia/Taipei');
        $end = $now->copy()->addDay();
        $items = [];
        $push = function ($at, string $icon, string $title, string $detail = '', string $note = '') use (&$items, $now) {
            $items[] = [
                'ts' => $at->timestamp,
                'time' => ($at->isSameDay($now) ? '今天' : '明天').' '.$at->format('H:i'),
                'icon' => $icon, 'title' => $title, 'detail' => $detail, 'note' => $note,
            ];
        };

        foreach (Automation::where('user_id', $uid)->where('enabled', true)->get() as $a) {
            if ($a->isAutoStopped()) {
                continue;
            }
            $t = (array) ($a->spec['trigger'] ?? []);
            $conds = collect((array) ($a->spec['conditions'] ?? []))->map(fn ($c) => match ($c['type'] ?? '') {
                'location_inside' => '需在'.($c['place'] ?? ''),
                'location_outside' => '需不在'.($c['place'] ?? ''),
                'weekday' => '限指定星期',
                'time_after' => '需晚於'.($c['time'] ?? ''),
                default => null,
            })->filter()->implode('、');
            $acts = implode('→', array_filter(array_map(fn ($x) => is_array($x) ? ($x['type'] ?? '') : '', (array) ($a->spec['actions'] ?? []))));
            switch ($t['type'] ?? '') {
                case 'daily':
                    [$h, $m] = array_pad(explode(':', (string) ($t['at'] ?? '00:00')), 2, '0');
                    for ($d = 0; $d <= 1; $d++) {
                        $at = $now->copy()->startOfDay()->addDays($d)->setTime((int) $h, (int) $m);
                        if ($at->lt($now) || $at->gt($end)) {
                            continue;
                        }
                        if (! empty($t['days']) && ! in_array($at->isoWeekday(), (array) $t['days'], true)) {
                            continue;
                        }
                        $push($at, '⚙️', $a->name, "動作：{$acts}", $conds);
                    }
                    break;
                case 'interval':
                    $ev = (int) ($t['every_min'] ?? 0);
                    if ($ev <= 0) {
                        break;
                    }
                    $mins = $now->hour * 60 + $now->minute;
                    $next = $now->copy()->startOfDay()->addMinutes((intdiv($mins, $ev) + 1) * $ev);
                    $push($next, '🔁', $a->name, "動作：{$acts}", "之後每 {$ev} 分鐘重複".($conds !== '' ? '；'.$conds : ''));
                    break;
                case 'unlock':
                    $win = (array) ($t['window'] ?? ['07:00', '09:30']);
                    for ($d = 0; $d <= 1; $d++) {
                        [$h, $m] = array_pad(explode(':', (string) ($win[0] ?? '07:00')), 2, '0');
                        $at = $now->copy()->startOfDay()->addDays($d)->setTime((int) $h, (int) $m);
                        if ($at->lt($now) || $at->gt($end)) {
                            continue;
                        }
                        if (! empty($t['days']) && ! in_array($at->isoWeekday(), (array) $t['days'], true)) {
                            continue;
                        }
                        $push($at, '🔓', $a->name, "動作：{$acts}", '解鎖手機時觸發（窗 '.implode('~', $win).'）'.($conds !== '' ? '；'.$conds : ''));
                    }
                    break;
            }
        }

        // 定時任務（「明天 8:30 開導航」）
        foreach (\App\Pai\Schedule\ScheduledTask::where('status', 'pending')
            ->whereBetween('run_at', [$now->copy()->utc(), $end->copy()->utc()])->get() as $st) {
            $push($st->run_at->timezone('Asia/Taipei'), '⏰', mb_substr((string) $st->command, 0, 60), '', $st->recur === 'daily' ? '每天重複' : '');
        }

        // 晨間簡報
        $s = app(\App\Pai\Settings\Settings::class);
        if ((bool) $s->get('briefing.enabled', true)) {
            [$h, $m] = array_pad(explode(':', (string) ($s->get('briefing.time') ?: '08:00')), 2, '0');
            for ($d = 0; $d <= 1; $d++) {
                $at = $now->copy()->startOfDay()->addDays($d)->setTime((int) $h, (int) $m);
                if ($at->gte($now) && $at->lte($end)) {
                    $push($at, '🌅', '晨間簡報', '天氣＋今日行程＋未讀信', '');
                    break;
                }
            }
        }

        // 進行中的守望（畫面/網頁盯梢）
        foreach (\App\Pai\Watch\WatchTask::where('user_id', $uid)->where('status', 'active')->get() as $w) {
            $kind = str_starts_with((string) $w->source, 'web:') ? '網頁盯梢' : '畫面守望';
            $unit = $w->interval_sec >= 60 ? intdiv($w->interval_sec, 60).' 分鐘' : $w->interval_sec.' 秒';
            $push($now, '👀', "{$kind}（進行中）：".mb_substr($w->goal, 0, 40), "每 {$unit} 看一次", '到期 '.$w->expires_at->timezone('Asia/Taipei')->format('m/d H:i'));
        }

        usort($items, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        return response()->json(['items' => array_values($items)]);
    }

    /** 匯出一條自動化（分享用 JSON：只含 name/spec，不含個人執行紀錄）。 */
    public function export(Request $request, int $id): JsonResponse
    {
        $uid = $this->uid($request);
        $auto = Automation::where('user_id', $uid)->find($id);
        if ($auto === null) {
            return response()->json(['error' => '找不到'], 404);
        }

        return response()->json([
            'pai_automation' => 1, // 格式版本
            'name' => $auto->name,
            'spec' => $auto->spec,
            'exported_at' => now()->toIso8601String(),
        ], 200, ['Content-Disposition' => 'attachment; filename="automation-'.$id.'.json"'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** 匯入分享來的自動化 JSON（只收 trigger/conditions/actions，預設停用讓使用者先檢視）。 */
    public function import(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        if ($uid === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $raw = (string) $request->input('json', '');
        $data = json_decode($raw, true);
        if (! is_array($data) || empty($data['pai_automation']) || ! is_array($data['spec'] ?? null)) {
            return response()->json(['error' => '不是有效的自動化分享檔（缺 pai_automation/spec）'], 422);
        }
        $spec = $data['spec'];
        if (empty($spec['trigger']) || empty($spec['actions'])) {
            return response()->json(['error' => 'spec 不完整（要有 trigger 與 actions）'], 422);
        }
        // 白名單過濾：只收已知欄位，防夾帶
        $spec = [
            'trigger' => (array) $spec['trigger'],
            'conditions' => array_values((array) ($spec['conditions'] ?? [])),
            'actions' => array_values((array) $spec['actions']),
        ];
        $auto = Automation::create([
            'user_id' => $uid,
            'name' => mb_substr(trim((string) ($data['name'] ?? '匯入的自動化')), 0, 60),
            'enabled' => false, // 預設停用：先看過內容再自己開
            'spec' => $spec, 'state' => [], 'source' => 'import',
        ]);

        return response()->json(['ok' => true, 'id' => $auto->id, 'name' => $auto->name]);
    }

    private function uid(Request $request): ?int
    {
        if ($request->user()) {
            return (int) $request->user()->id;
        }

        return GatewayController::ownerFromRequest($request)?->id;
    }

    /** @return array{automations: array, thoughts: array} */
    private function gather(int $uid): array
    {
        $automations = Automation::where('user_id', $uid)->orderByDesc('id')->get()->map(function (Automation $a) {
            $t = (array) ($a->spec['trigger'] ?? []);
            $trigger = match ($t['type'] ?? '') {
                'daily' => "每天 {$t['at']}".(empty($t['days']) ? '' : '（限上班日）'),
                'interval' => "每 {$t['every_min']} 分鐘",
                'unlock' => '早晨解鎖手機時',
                default => '—',
            };
            $acts = array_map(fn ($x) => (string) (is_array($x) ? ($x['type'] ?? '') : $x), (array) ($a->spec['actions'] ?? []));

            return [
                'id' => $a->id, 'name' => $a->name, 'enabled' => $a->enabled,
                'trigger' => $trigger, 'actions' => implode('→', array_filter($acts)),
                'source' => $a->source, 'created' => $a->created_at?->format('Y-m-d H:i'),
                'expires_at' => $a->expires_at?->format('Y-m-d\TH:i'), // datetime-local 用
                'max_runs' => $a->max_runs,
                'run_count' => (int) $a->run_count,
                'auto_stop' => $a->autoStopLabel(),
            ];
        })->values();

        // AI 主動思考記錄（proactive 對話的 assistant 訊息）
        $conv = Conversation::where('voice_sid', "proactive:{$uid}")->latest('id')->first();
        $thoughts = [];
        if ($conv) {
            $thoughts = $conv->messages()->where('role', 'assistant')->latest('id')->limit(40)->get()
                ->map(fn ($m) => [
                    'at' => data_get($m->meta, 'at') ?: $m->created_at?->format('Y-m-d H:i'),
                    'acted' => (bool) data_get($m->meta, 'acted', false),
                    'text' => mb_substr((string) $m->content, 0, 300),
                ])->values()->all();
        }

        // 內建自動化（per-account 設定開關）
        $s = app(\App\Pai\Settings\Settings::class);
        $builtins = [
            ['key' => 'commute.enabled', 'name' => '🚗 通勤遲到提醒', 'desc' => '上班時間到還沒到公司 → 提醒並可傳訊息給主管', 'enabled' => (bool) $s->get('commute.enabled', false, $uid)],
            ['key' => 'event_guard.enabled', 'name' => '🗓️ 行程出發提醒', 'desc' => '行事曆有地點的事件，到該出發時提醒並可開導航/通知對方', 'enabled' => (bool) $s->get('event_guard.enabled', false, $uid)],
            ['key' => 'proactive.enabled', 'name' => '🧠 AI 主動思考', 'desc' => 'AI 定期自己判斷要不要主動提醒或建立自動化', 'enabled' => (bool) $s->get('proactive.enabled', false, $uid)],
        ];

        return ['automations' => $automations, 'thoughts' => $thoughts, 'builtins' => $builtins];
    }
}
