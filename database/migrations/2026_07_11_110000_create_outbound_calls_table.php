<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 外撥電話（「幫我打去餐廳訂位」）：一筆 = 一通外撥任務。
 * 由 Twilio 雲端號碼撥出，AI 用 TwiML <Say>+<Gather speech> 跟對方回合制對話，
 * 達成目標（或確定失敗）→ 掛斷 → 總結結果通知使用者。
 *   to_number   對方號碼（E.164）
 *   goal        要達成什麼（含店名/時間/人數/訂位姓名等必要資訊）
 *   status      pending/in_progress/completed/no_answer/busy/failed/canceled
 *   transcript  逐字稿 [{role: ai|callee, text}]
 *   result      一句話結果（訂到了幾點幾位 / 沒訂到原因）
 *   token       webhook URL 權杖（Twilio 回呼身分驗證）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('to_number');
            $table->text('goal');
            $table->string('status')->default('pending')->index();
            $table->json('transcript')->nullable();
            $table->text('result')->nullable();
            $table->string('twilio_sid')->nullable();
            $table->string('token', 64)->unique();
            $table->unsignedInteger('turns')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('outbound_calls');
    }
};
