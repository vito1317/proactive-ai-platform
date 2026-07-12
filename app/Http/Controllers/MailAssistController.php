<?php

namespace App\Http\Controllers;

use App\Pai\Integrations\InboxAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 收件匣助理：通知按鈕「寄出草稿/不用」。驗證同其他手機按鈕（session 或 device token）。 */
class MailAssistController extends Controller
{
    public function decide(Request $request, InboxAssistant $inbox): JsonResponse
    {
        $user = $request->user() ?? GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $send = (string) $request->input('decision', 'discard') === 'send';

        return response()->json(['ok' => true, 'message' => $inbox->decide((int) $user->id, $send)]);
    }
}
