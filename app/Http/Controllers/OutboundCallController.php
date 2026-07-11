<?php

namespace App\Http\Controllers;

use App\Pai\Call\OutboundCall;
use App\Pai\Call\OutboundCaller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * AI 外撥電話的 Twilio 回呼：
 *   turn   — 通話中每回合（接通第一句 / <Gather> 辨識到對方說話）→ 回下一段 TwiML
 *   status — 通話結束（completed/no-answer/busy/failed）→ 總結＋通知使用者
 * 以 URL token（不可猜的隨機字串）驗證身分，同其他 webhook 慣例。
 */
class OutboundCallController extends Controller
{
    public function __construct(private readonly OutboundCaller $caller) {}

    public function turn(Request $request, string $token): Response
    {
        $call = OutboundCall::where('token', $token)->first();
        if (! $call || in_array($call->status, ['completed', 'failed', 'canceled'], true)) {
            return $this->xml('<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>');
        }
        // 第一回合（剛接通）不帶 SpeechResult；之後每回合帶對方說的話（可能為空字串=靜默）
        $speech = $request->has('SpeechResult') ? (string) $request->input('SpeechResult', '') : null;

        return $this->xml($this->caller->turn($call, $speech));
    }

    public function status(Request $request, string $token): Response
    {
        $call = OutboundCall::where('token', $token)->first();
        $st = (string) $request->input('CallStatus', '');
        if ($call && in_array($st, ['completed', 'no-answer', 'busy', 'failed', 'canceled'], true)) {
            $this->caller->finalize($call, $st);
        }

        return $this->xml('<?xml version="1.0" encoding="UTF-8"?><Response></Response>');
    }

    private function xml(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/xml');
    }
}
