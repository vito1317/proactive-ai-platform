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
        $msg = $engine->decide($user->id, (int) $request->input('id', 0), (string) $request->input('branch', 'no'));

        return response()->json(['ok' => true, 'message' => $msg]);
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
