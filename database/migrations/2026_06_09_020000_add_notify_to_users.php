<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 每帳號自己的通知頻道（TG chat id / LINE target）：notify = {tg_chat_id, line_to}。
 * 通知會送到「任務擁有者」自己的聊天室，不同帳號分流。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notify')->nullable()->after('caps');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notify'));
    }
};
