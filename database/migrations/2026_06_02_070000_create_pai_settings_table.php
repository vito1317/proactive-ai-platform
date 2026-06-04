<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 後台可調參數：覆寫 config/pai.php 的預設值（key 用點記法，如 llm.base_url）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pai_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pai_settings');
    }
};
