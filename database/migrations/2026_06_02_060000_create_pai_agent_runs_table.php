<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L3 認知層：一次協調者運行的完整軌跡。
 * 記錄 ReAct 步驟、發現、建議/執行的動作——讓中控台能「看見」AI 如何思考與行動。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pai_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('pai_events')->cascadeOnDelete();
            $table->string('domain')->index();
            $table->string('coordinator');
            $table->string('status')->default('running')->index();
            $table->json('steps')->nullable();      // ReAct 軌跡 [{step, thought, action, action_input, observation}]
            $table->json('findings')->nullable();    // 分析發現 [string]
            $table->json('actions')->nullable();     // 動作 [{action, rationale, risk, status}]
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pai_agent_runs');
    }
};
