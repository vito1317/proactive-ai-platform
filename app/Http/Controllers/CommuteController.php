<?php

namespace App\Http\Controllers;

use App\Pai\Commute\CommuteGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 通勤遲到提醒的「傳給主管 / 不用」按鈕後端。認證沿用 gateway X-Register-Secret（per-device token）。
 */
class CommuteController extends Controller
{
    /** 手機解鎖時呼叫：立刻做一次早晨通勤檢查 + 跑 unlock 觸發的自動化流程（你醒了才提醒，避免睡死沒看到）。 */
    public function wake(Request $request, CommuteGuard $guard): JsonResponse
    {
        $user = GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $guard->wake($user);
        try {
            app(\App\Pai\Automation\AutomationEngine::class)->onUnlock($user);
        } catch (\Throwable) {
        }

        return response()->json(['ok' => true]);
    }

    public function decide(Request $request, CommuteGuard $guard): JsonResponse
    {
        $user = GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $decision = (string) $request->input('decision', 'skip');

        if ($decision === 'map') {
            $msg = $guard->openMap($user->id);

            return response()->json(['ok' => true, 'message' => $msg]);
        }

        if ($decision !== 'send') {
            \Illuminate\Support\Facades\Cache::forget("commute:pending:{$user->id}");
            $guard->speak($user->id, '好，這次不傳訊息給主管。');

            return response()->json(['ok' => true, 'message' => '好，這次不傳訊息給主管。']);
        }

        $msg = $guard->sendToManager($user->id, (string) $request->input('node', ''));
        $guard->speak($user->id, $msg);

        return response()->json(['ok' => true, 'message' => $msg]);
    }
}
