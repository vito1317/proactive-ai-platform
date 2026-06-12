<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 每裝置長期憑證（QR 配對換得）：token_hash → 擁有者帳號 + 裝置名。
 * 取代「所有裝置共用 register_secret」的免登入模式；可在後台撤銷單一裝置。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('name');                 // 裝置名（= mcp_servers.name 前綴）
            $table->string('token_hash')->unique();  // sha256(device_token)
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
