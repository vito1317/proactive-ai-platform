<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // #5 檢查點：改檔/改設定前的快照，可一鍵還原
        Schema::create('checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('kind');                 // file | setting
            $table->string('target');               // 檔案路徑 或 設定鍵
            $table->longText('before')->nullable(); // 原內容（新檔為 null）
            $table->boolean('existed')->default(true);
            $table->string('label')->default('');
            $table->boolean('restored')->default(false);
            $table->timestamps();
        });

        // #9 LLM 用量觀測：每日彙總
        Schema::create('llm_usages', function (Blueprint $table) {
            $table->id();
            $table->date('day')->unique();
            $table->unsignedBigInteger('calls')->default(0);
            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('latency_ms')->default(0); // 累計，配合 calls 算平均
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkpoints');
        Schema::dropIfExists('llm_usages');
    }
};
