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

        return ['automations' => $automations, 'thoughts' => $thoughts];
    }
}
