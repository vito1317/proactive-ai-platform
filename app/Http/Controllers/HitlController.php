<?php

namespace App\Http\Controllers;

use App\Pai\Cognition\AgentRun;
use App\Pai\Governance\Hitl;
use App\Pai\Notify\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 裝置端核准 API：手機通知上的「接受 / 拒絕」按鈕直接打這裡。
 * 認證沿用 gateway 的 X-Register-Secret（per-device token 或共用密鑰）。
 * 待核准動作多屬平台/領域層級（如 WAF 修補協調者）→ 僅管理員可核准。
 */
class HitlController extends Controller
{
    public function decide(Request $request, Hitl $hitl, Notifier $notifier): JsonResponse
    {
        $user = GatewayController::ownerFromRequest($request);
        if ($user === null || ! $user->isAdmin()) {
            return response()->json(['error' => '無核准權限'], 403);
        }

        $data = $request->validate([
            'run_id' => ['required', 'integer'],
            'decision' => ['required', 'in:approve,reject'],
            'index' => ['nullable', 'integer', 'min:0'], // 省略 → 處理該 run 所有待核准動作
        ]);

        $run = AgentRun::find($data['run_id']);
        if ($run === null) {
            return response()->json(['error' => '找不到此運行'], 404);
        }

        $res = $hitl->decide($run, $data['decision'], $data['index'] ?? null);

        // 回一則確認通知到手機（取代原本那則待核准通知的觀感）
        try {
            $notifier->send('🛡️ '.$res['message']);
        } catch (\Throwable) {
        }

        return response()->json(['ok' => $res['ok'], 'message' => $res['message']]);
    }
}
