<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Call\OutboundCall;
use App\Pai\Call\OutboundCaller;
use App\Pai\Skills\Skill;
use Illuminate\Support\Str;

/**
 * AI 外撥電話：「幫我打去餐廳訂位」——用雲端號碼（Twilio）撥給店家，
 * AI 全程用語音跟對方對話把事情講成，講完把結果通知使用者＋手機念出。
 * 注意與 phone_call（手機直接撥號、使用者自己講）的差別：這個是 AI 代講。
 */
class PlaceCallSkill implements Skill
{
    public function __construct(private readonly OutboundCaller $caller) {}

    public function name(): string
    {
        return 'place-call';
    }

    public function description(): string
    {
        return 'AI 代打電話出去講事情（訂位/預約/詢問/取消預約）：用雲端號碼撥給店家或客服，AI 用語音跟對方對話，'
            .'講完回報結果。goal 必須含所有必要資訊（店名、日期、時間、人數、訂位姓名、聯絡電話），缺就先問使用者。'
            .'號碼不知道就先用其他工具查到電話再呼叫。若使用者只是要「用手機撥號自己講」，用 phone_call 而不是這個。';
    }

    public function parameters(): array
    {
        return [
            'to' => '對方電話（E.164 格式，台灣市話例 +886223456789、手機例 +886912345678）',
            'goal' => '要達成什麼＋所有必要資訊（例：向鼎泰豐信義店訂位，7/15 晚上7點 4 位，姓名王小明，聯絡電話0912345678）',
        ];
    }

    public function isHighRisk(): bool
    {
        return true; // 會真的撥給對方＋產生話費 → 撥號前要使用者確認
    }

    public function run(array $args): string
    {
        $uid = Tenant::id();
        if ($uid === null) {
            return '無法判斷帳號，請在登入情境下使用。';
        }
        if ($err = $this->caller->configError($uid)) {
            return $err;
        }
        $to = preg_replace('/[\s\-()]/', '', (string) ($args['to'] ?? ''));
        // 台灣本地寫法自動轉 E.164（0912… / 02…）
        if (preg_match('/^0\d{8,9}$/', $to)) {
            $to = '+886'.substr($to, 1);
        }
        if (! preg_match('/^\+\d{8,15}$/', $to)) {
            return '電話號碼格式不對（要 E.164，如 +886223456789）。若還不知道號碼，先查到再叫我打。';
        }
        $goal = trim((string) ($args['goal'] ?? ''));
        if (mb_strlen($goal) < 8) {
            return '目標太簡略。請提供要達成什麼＋必要資訊（店名/日期時間/人數/訂位姓名/聯絡電話）。';
        }
        if (OutboundCall::where('user_id', $uid)->where('status', 'in_progress')->where('created_at', '>', now()->subMinutes(10))->exists()) {
            return '已有一通電話正在進行中，等它結束再打下一通。';
        }

        $call = OutboundCall::create([
            'user_id' => $uid, 'to_number' => $to, 'goal' => $goal,
            'token' => Str::random(48),
        ]);
        if (! $this->caller->place($call)) {
            return "❌ 撥號失敗：{$call->result}";
        }

        return "📞 已撥出（#{$call->id}）給 {$to}，我會照這個目標跟對方講：{$goal}。"
            .'講完（或對方沒接）會通知你結果＋逐字稿，並用手機念給你聽。';
    }
}
