<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自動化「自動停止」：給每條流程（尤其 AI 自建的）一個截止條件，
 * 到期或跑滿次數就自動停用，不會無限期跑下去。
 *   expires_at  到這個時間點之後自動停用（null = 不限時間）
 *   max_runs    成功執行幾次後自動停用（null = 不限次數）
 *   run_count   已成功執行次數
 *   last_run_at 最近一次執行時間（檢視用）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('source');
            $table->unsignedInteger('max_runs')->nullable()->after('expires_at');
            $table->unsignedInteger('run_count')->default(0)->after('max_runs');
            $table->timestamp('last_run_at')->nullable()->after('run_count');
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'max_runs', 'run_count', 'last_run_at']);
        });
    }
};
