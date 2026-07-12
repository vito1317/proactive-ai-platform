<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 會議模式：「開始記會議」→ 手機持續錄音分段上傳 → Whisper 轉寫累積 transcript →
 * 「結束會議」→ LLM 摘要＋決議＋待辦（有期限的直接建成定時任務）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('status')->default('recording')->index(); // recording/summarizing/done/error
            $table->text('transcript')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('meetings');
    }
};
