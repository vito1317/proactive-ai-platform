<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 視覺守望（「幫我盯著這個畫面，X 發生就叫我」）：
 * 一筆 = 一個守望任務。背景 Job 依 interval_sec 週期截手機畫面 → 視覺 LLM 判斷
 * 是否命中 goal → 命中就通知＋手機念出並結束；到 expires_at 沒命中則自動收尾。
 *   node        盯哪台反向節點（手機）
 *   goal        要盯什麼、發生什麼要叫人（自然語言）
 *   status      active / hit / expired / cancelled / error
 *   last_desc   上一輪畫面的一句話狀態（給下一輪比對「有沒有變化」）
 *   last_hash   上一輪截圖的 md5（畫面完全沒變就跳過 LLM，省推理）
 *   tick_token  當前合法 tick 鏈的權杖（防佇列重啟/看門狗補發造成多條鏈並行）
 *   fail_count  連續截圖失敗次數（連 3 次 → error 收尾）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('node')->nullable();
            $table->text('goal');
            $table->unsignedInteger('interval_sec')->default(20);
            $table->timestamp('expires_at');
            $table->string('status')->default('active')->index();
            $table->text('last_desc')->nullable();
            $table->string('last_hash', 64)->nullable();
            $table->string('tick_token', 36)->nullable();
            $table->unsignedInteger('fail_count')->default(0);
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('watch_tasks');
    }
};
