<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L2 長期/情境記憶（向量 RAG）。每筆記憶帶嵌入向量；database 驅動以 JSON 存、
 * PHP 算餘弦。production 改 pgvector 時，embedding 改為 vector 欄位。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pai_memories', function (Blueprint $table) {
            $table->id();
            $table->string('namespace')->index();   // 領域記憶隔離
            $table->string('kind')->default('note'); // incident / patch / note ...
            $table->text('content');
            $table->longText('embedding');           // JSON 編碼的向量（database 驅動）
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pai_memories');
    }
};
