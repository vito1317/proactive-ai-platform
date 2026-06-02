<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L1 感知層：事件匯流排的持久化。每筆外部事件（webhook / cron）
 * 落地成一列，經正規化標記 intent/severity 後路由到領域協調者。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pai_events', function (Blueprint $table) {
            $table->id();
            $table->string('source');                 // 來源系統，如 siem / git / cron
            $table->string('topic')->index();         // 事件主題，如 siem.alert / ci.failed
            $table->json('payload');                  // 原始負載
            $table->string('intent')->nullable();     // 正規化後意圖
            $table->string('severity')->nullable()->index();
            $table->string('domain')->nullable()->index();  // 路由到的領域
            $table->string('status')->default('received')->index();
            $table->text('note')->nullable();         // 路由/略過原因
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pai_events');
    }
};
