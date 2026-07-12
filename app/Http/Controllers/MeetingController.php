<?php

namespace App\Http\Controllers;

use App\Pai\Chat\SpeechToText;
use App\Pai\Meeting\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 會議模式：手機分段錄音上傳 → Whisper 轉寫 → 累積進行中會議的逐字稿。 */
class MeetingController extends Controller
{
    public function chunk(Request $request, SpeechToText $stt): JsonResponse
    {
        $user = $request->user() ?? GatewayController::ownerFromRequest($request);
        if ($user === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $request->validate(['audio_base64' => ['required', 'string']]);
        $m = Meeting::activeFor((int) $user->id);
        if ($m === null) {
            return response()->json(['ok' => false, 'error' => 'no_active_meeting']);
        }
        $text = $stt->transcribe($data['audio_base64']);
        if ($text !== null && $text !== '') {
            $m->appendTranscript($text);
        }

        return response()->json(['ok' => true, 'text' => (string) $text]);
    }
}
