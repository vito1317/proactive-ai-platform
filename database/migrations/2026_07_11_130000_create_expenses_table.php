<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 語音記帳：「剛剛午餐花 120」→ 一筆支出。
 * 查詢/月結由 expense-report 技能與每月 1 號的自動摘要使用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->decimal('amount', 10, 2);
            $table->string('item');                       // 午餐、咖啡、加油…
            $table->string('category')->nullable();       // 餐飲/交通/購物…（LLM 自動歸類）
            $table->timestamp('spent_at')->index();       // 消費時間（預設=記帳當下）
            $table->string('source')->default('voice');   // voice / chat
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('expenses');
    }
};
