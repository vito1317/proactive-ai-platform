<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 已接入的 MCP server（L4 外部工具來源）。可由對話自然語言新增/管理。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();           // 代號（工具名前綴）
            $table->string('url');                       // Streamable HTTP MCP 端點
            $table->json('headers')->nullable();         // 認證標頭（值可用 {{vault:NAME}} 佔位）
            $table->boolean('enabled')->default(true);
            $table->json('tools')->nullable();           // tools/list 快取
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
