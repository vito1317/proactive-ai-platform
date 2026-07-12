<?php

namespace App\Http\Controllers;

use App\Pai\Notify\NotificationTriage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 通知分流：手機轉送 App 通知進來 → 分級（urgent 立刻吵/normal 摘要/noise 靜音）。 */
class NotifyTriageController extends Controller
{
    public function handle(Request $request, NotificationTriage $triage): JsonResponse
    {
        $user = $request->user() ?? GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $request->validate([
            'app' => ['required', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:200'],
            'text' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['ok' => true, 'class' => $triage->handle(
            (int) $user->id, $data['app'], (string) ($data['title'] ?? ''), (string) ($data['text'] ?? '')
        )]);
    }
}
