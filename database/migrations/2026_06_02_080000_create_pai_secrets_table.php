<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 零信任機密金庫。值以 Laravel Crypt 加密存放——
 * 智能體永遠拿不到明文，只持有 {{vault:NAME}} 佔位符，
 * 真正憑證在請求離開可信區時由 EgressGateway 於網路層注入。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pai_secrets', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->text('ciphertext');         // Crypt::encryptString 後的值
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pai_secrets');
    }
};
