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
        $auto->enabled = $action === 'enable' ? true : ($action === 'disable' ? false : ! $auto->enabled);
        $auto->save();

        return response()->json(['ok' => true, 'enabled' => $auto->enabled]);
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
