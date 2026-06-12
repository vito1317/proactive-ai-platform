<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 多租戶 / RBAC 基礎（P1）。
 * - users：role(admin/user) + status + caps(能力旗標 JSON：all_devices / all_skills / memory)
 * - 各資源表加 user_id 擁有者：mcp_servers / pai_memories / learned_skills / scheduled_tasks / slash_commands
 *   （user_memories / conversations 已有 user_id）
 * - 逐資源授權：device_grants（帳號↔裝置）、skill_grants（帳號↔skill 名）
 * - backfill：現有第一個使用者設為 admin，所有既有資料歸屬該 admin
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('password');       // admin | user
            $table->string('status')->default('active')->after('role');        // active | disabled
            $table->json('caps')->nullable()->after('status');                 // {all_devices,all_skills,memory}
        });

        foreach (['mcp_servers', 'pai_memories', 'learned_skills', 'scheduled_tasks', 'slash_commands'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'user_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->foreignId('user_id')->nullable()->index();   // 擁有者；null=共用/admin
                });
            }
        }

        Schema::create('device_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('mcp_server_id')->index();
            $table->timestamps();
            $table->unique(['user_id', 'mcp_server_id']);
        });

        Schema::create('skill_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('skill_name');
            $table->timestamps();
            $table->unique(['user_id', 'skill_name']);
        });

        // ── backfill：第一個使用者升為 admin（全權），既有資料歸屬他 ──
        $adminId = DB::table('users')->min('id');
        if ($adminId !== null) {
            DB::table('users')->where('id', $adminId)->update([
                'role' => 'admin',
                'caps' => json_encode(['all_devices' => true, 'all_skills' => true, 'memory' => true]),
            ]);
            foreach (['mcp_servers', 'pai_memories', 'learned_skills', 'scheduled_tasks', 'slash_commands', 'user_memories', 'conversations'] as $t) {
                if (Schema::hasTable($t) && Schema::hasColumn($t, 'user_id')) {
                    DB::table($t)->whereNull('user_id')->update(['user_id' => $adminId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_grants');
        Schema::dropIfExists('device_grants');
        foreach (['mcp_servers', 'pai_memories', 'learned_skills', 'scheduled_tasks', 'slash_commands'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'user_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('user_id'));
            }
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'status', 'caps']));
    }
};
