<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 情境模式（Scenes）：把多個動作打包成一句話可觸發的 profile。
 * 「睡覺模式」＝手機勿擾＋設鬧鐘＋明早提醒…。actions 沿用 AutomationEngine 的動作格式
 * （speak/notify/agent/open_map/ask），語音說「○○模式」即執行。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name');                 // 睡覺、上班、回家…（不含「模式」二字）
            $table->json('actions');                // AutomationEngine 動作陣列
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::drop('scenes');
    }
};
