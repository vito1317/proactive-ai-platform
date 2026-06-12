<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 治理層回饋紀錄：人類核准/駁回動作的歷史，供 ProactivityPolicy 自動降級常被拒絕的動作。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pai_action_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('action');
            $table->boolean('positive');   // true=核准 false=駁回
            $table->timestamps();
            $table->index(['domain', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pai_action_feedback');
    }
};
