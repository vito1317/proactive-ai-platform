<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 使用者自動化流程（AI 可自建）：觸發(trigger) → 條件(conditions) → 動作(actions)。
 * 通勤遲到提醒就是這種流程的一個實例；此表讓 AI／使用者再建任意類似流程。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->json('spec');                 // {trigger, conditions[], actions[]}
            $table->json('state')->nullable();    // 去重/上次執行等執行期狀態
            $table->string('source')->default('ai'); // ai | user
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
