<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            // 使用者定時任務：「明天早上8:30幫我開導航到台中」→ 到點丟給指揮大腦執行
            $table->id();
            $table->text('command');                          // 要執行的白話指令
            $table->dateTime('run_at')->index();              // 下次執行時間
            $table->string('recur')->nullable();              // null=一次性；daily=每天
            $table->foreignId('conversation_id')->nullable(); // 結果回到哪段對話（語音/TG）
            $table->string('status')->default('pending')->index(); // pending|done|cancelled
            $table->dateTime('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
