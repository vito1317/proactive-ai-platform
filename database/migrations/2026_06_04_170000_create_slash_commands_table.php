<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 自訂斜線指令：/name → 展開成 body 後照常處理（聊天室 / TG / LINE 共用）。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slash_commands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // 不含斜線，小寫，如 waf
            $table->text('body');                       // 展開內容（可含 {{args}}）
            $table->string('description')->nullable();  // 給 TG 指令選單顯示
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slash_commands');
    }
};
