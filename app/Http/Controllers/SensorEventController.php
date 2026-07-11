<?php

namespace App\Http\Controllers;

use App\Pai\Safety\SafetyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 手機傳感器哨兵回報端點：
 *   POST /api/sensor/event  {type: impact|fall|collision_warning, magnitude, lat, lng}
 *   POST /api/sensor/decide {decision: ok|help}   ← 通知按鈕「我沒事/需要幫忙」
 * 驗證：per-device 憑證（X-Register-Secret，同 gateway）或登入 session。
 */
class SensorEventController extends Controller
{
    public function __construct(private readonly SafetyGuard $guard) {}

    public function event(Request $request): JsonResponse
    {
        $user = $request->user() ?? GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $request->validate([
            'type' => ['required', 'string', 'max:32'],
            'magnitude' => ['nullable', 'numeric'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'spoken' => ['nullable', 'boolean'], // 手機端已本地念過警示
        ]);

        return response()->json(['ok' => true, 'state' => $this->guard->handleEvent((int) $user->id, $data)]);
    }

    public function decide(Request $request): JsonResponse
    {
        $user = $request->user() ?? GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $ok = (string) $request->input('decision', 'ok') !== 'help';

        return response()->json(['ok' => true, 'message' => $this->guard->resolve((int) $user->id, $ok)]);
    }
}
