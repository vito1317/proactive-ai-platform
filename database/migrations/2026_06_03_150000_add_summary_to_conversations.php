<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 自動上下文壓縮：舊訊息摘要存 summary，compacted_through_id 之前的訊息不再進 prompt。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('title');
            $table->unsignedBigInteger('compacted_through_id')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['summary', 'compacted_through_id']);
        });
    }
};
