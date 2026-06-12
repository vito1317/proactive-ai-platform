<?php

namespace App\Http\Controllers;

use App\Pai\Chat\GenericChannelReplyJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Twilio 來訊 SMS webhook（雙向）：收到簡訊 → 背景跑對話大腦 → 用 Twilio API 回簡訊。 */
class TwilioWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $from = trim((string) $request->input('From', ''));
        $body = trim((string) $request->input('Body', ''));
        if ($from !== '' && $body !== '') {
            GenericChannelReplyJob::dispatch('sms', $from, $body, ['from' => $from]);
        }

        // 立即回空 TwiML（真結果由背景用 Twilio API 非同步發；避免 AI 慢於 Twilio 逾時）
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)
            ->header('Content-Type', 'text/xml');
    }
}
