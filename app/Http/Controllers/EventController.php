<?php

namespace App\Http\Controllers;

use App\Pai\Commute\EventGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 行程出發提醒按鈕後端（開導航 / 通知對方 / 知道了）。device token 認證。 */
class EventController extends Controller
{
    public function decide(Request $request, EventGuard $guard): JsonResponse
    {
        $user = GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => '未授權'], 403);
        }
        $node = (string) $request->input('node', '');
        $msg = match ((string) $request->input('decision', 'skip')) {
            'map' => $guard->openMap($user->id, $node),
            'notify' => $guard->notifyAttendee($user->id, $node),
            default => '好，知道了。',
        };

        return response()->json(['ok' => true, 'message' => $msg]);
    }
}
