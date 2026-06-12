<?php

namespace App\Http\Controllers;

use App\Pai\Automation\AutomationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 自動化流程「ask」動作的按鈕後端（接受/拒絕各跑一串動作）+ 解鎖觸發。
 * 認證沿用 gateway X-Register-Secret（per-device token）。
 */
class AutomationController extends Controller
{
    public function decide(Request $request, AutomationEngine $engine): JsonResponse
    {
        $user = GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $msg = $engine->decide($user->id, (int) $request->input('id', 0), (string) $request->input('branch', 'no'), (string) $request->input('node', ''));

        return response()->json(['ok' => true, 'message' => $msg]);
    }

    /** 取消操作：中止這個使用者進行中的 agent 對話（通勤傳訊/自動化/主動思考/最近對話），不再對手機下指令。 */
    public function abort(Request $request): JsonResponse
    {
        $user = GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $uid = $user->id;
        $ids = \App\Pai\Chat\Conversation::where('user_id', $uid)
            ->whereIn('voice_sid', ["commute:{$uid}", "automation:{$uid}", "proactive:{$uid}"])
            ->orWhere('user_id', $uid)->latest('id')->limit(5)->pluck('id')->all();
        foreach (array_unique($ids) as $cid) {
            \Illuminate\Support\Facades\Cache::put("pai:abort:{$cid}", true, 120);
            \Illuminate\Support\Facades\Cache::put("pai:chat:abort:{$cid}", true, 120);
        }

        return response()->json(['ok' => true, 'message' => '已取消操作。']);
    }

    /** 手機解鎖：跑 unlock 觸發的自動化流程。 */
    public function wake(Request $request, AutomationEngine $engine): JsonResponse
    {
        $user = GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $engine->onUnlock($user);

        return response()->json(['ok' => true]);
    }
}
