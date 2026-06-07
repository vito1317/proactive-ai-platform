<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_memories', function (Blueprint $table) {
            // 跨對話長期記憶：使用者的個人事實/偏好（住汐止、喜歡魯肉飯、家人稱呼…）
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('category')->default('fact'); // identity|location|preference|dislike|contact|fact|routine
            $table->text('content');
            $table->boolean('pinned')->default(false);    // 釘選＝永不自動淘汰
            $table->unsignedInteger('hits')->default(0);   // 被用到次數（淘汰排序用）
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memories');
    }
};
