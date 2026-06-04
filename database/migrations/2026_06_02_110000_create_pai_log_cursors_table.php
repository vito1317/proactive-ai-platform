<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 記錄每個受監控日誌檔已掃描到的位元組位置，確保只處理新增內容、不重複。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pai_log_cursors', function (Blueprint $table) {
            $table->string('path')->primary();
            $table->unsignedBigInteger('offset')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pai_log_cursors');
    }
};
