<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learned_skills', function (Blueprint $table) {
            // 自我改進：agent 完成複雜任務後，把「成功的做法」存成可重用的 playbook，
            // 下次遇到類似需求就注入提示讓它照做（越用越快、越穩）。
            $table->id();
            $table->string('name');                 // 技能名（白話，如「在LINE傳訊息給某人」）
            $table->text('when_to_use');            // 什麼時候用（觸發情境）
            $table->text('steps');                  // 成功步驟（工具序列＋要點）
            $table->string('keywords')->default(''); // 命中關鍵字（空白分隔）
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learned_skills');
    }
};
