<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'role', 'status', 'caps', 'notify'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'caps' => 'array',
            'notify' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    /** 能力旗標（admin 一律 true）。 */
    public function cap(string $key): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return (bool) (($this->caps ?? [])[$key] ?? false);
    }

    /** 此帳號可存取的裝置（mcp_servers）id 清單：擁有的 + 被授權的。admin=全部。 */
    public function allowedDeviceIds(): array
    {
        if ($this->isAdmin() || $this->cap('all_devices')) {
            return \App\Pai\Mcp\McpServer::pluck('id')->all();
        }
        $owned = \App\Pai\Mcp\McpServer::where('user_id', $this->id)->pluck('id')->all();
        $granted = DB::table('device_grants')->where('user_id', $this->id)->pluck('mcp_server_id')->all();

        return array_values(array_unique(array_merge($owned, $granted)));
    }

    /** 此帳號可存取的裝置名稱清單（mcp_servers.name + 主節點 local）。admin / all_devices=全部。 */
    public function allowedDeviceNames(): array
    {
        if ($this->isAdmin() || $this->cap('all_devices')) {
            return array_merge(['local'], \App\Pai\Mcp\McpServer::pluck('name')->all());
        }
        $names = \App\Pai\Mcp\McpServer::whereIn('id', $this->allowedDeviceIds())->pluck('name')->all();
        // 主節點（PAI 伺服器本身）：只有被授權 caps.local 才可操作
        if ($this->cap('local')) {
            array_unshift($names, 'local');
        }

        return $names;
    }

    /** 是否可操作主節點（PAI 伺服器本身：跑 exec／開程式／瀏覽器等）。 */
    public function canUseLocal(): bool
    {
        return $this->isAdmin() || $this->cap('all_devices') || $this->cap('local');
    }

    /** 此帳號是否可用某個 skill（依名稱）。admin / all_skills=全部；否則看 skill_grants。 */
    public function canUseSkill(string $skillName): bool
    {
        if ($this->isAdmin() || $this->cap('all_skills')) {
            return true;
        }

        return DB::table('skill_grants')->where('user_id', $this->id)->where('skill_name', $skillName)->exists();
    }

    /** 此帳號被授權的 skill 名稱清單（給目錄過濾用）。 */
    public function allowedSkillNames(): array
    {
        return DB::table('skill_grants')->where('user_id', $this->id)->pluck('skill_name')->all();
    }
}
