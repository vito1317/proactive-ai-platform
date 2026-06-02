<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Telegram 雙向：以 tg_chat_id 對應一個會話，維持該 TG 對話的上下文。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('tg_chat_id')->nullable()->index()->after('user_id'); // Telegram 對應
            $table->string('line_to')->nullable()->index()->after('tg_chat_id'); // LINE userId/groupId/roomId 對應
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['tg_chat_id', 'line_to']);
        });
    }
};
