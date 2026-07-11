<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 守望來源：screen＝截手機螢幕（預設）；live:{session}＝吃「即時投影/鏡頭」推上來的畫面
 * （Cache vision:pending:{session}，手機/網頁持續推送）。即時來源不用叫手機截圖，
 * 間隔可以更短；投影停止推送（cache 過期）會走連續失敗自動收尾。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watch_tasks', function (Blueprint $table) {
            $table->string('source')->default('screen')->after('node');
        });
    }

    public function down(): void
    {
        Schema::table('watch_tasks', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
