<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Pai\Mcp\McpServer;
use App\Pai\Skills\SkillRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 管理員：帳號管理 + 逐資源授權（裝置 / skills / 能力旗標）。完全獨立租戶。
 * 全部方法僅 admin 可用。
 */
class AdminController extends Controller
{
    private function gate(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, '僅限管理員');
    }

    public function index(Request $request, SkillRegistry $registry): Response
    {
        $this->gate($request);

        // 可授權的內建 skills（非 MCP 工具）：用空 allowedNodes 濾掉所有裝置工具，只留 builtin
        $skills = collect($registry->dedupedSkills(null, []))
            ->map(fn ($s) => ['name' => $s->name(), 'description' => $s->description()])
            ->sortBy('name')->values();

        $devices = McpServer::orderBy('name')->get(['id', 'name', 'user_id']);

        $users = User::orderBy('id')->get()->map(function (User $u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'status' => $u->status ?? 'active',
                'caps' => (object) ($u->caps ?? []),
                'notify' => (object) ($u->notify ?? []),
                'device_ids' => DB::table('device_grants')->where('user_id', $u->id)->pluck('mcp_server_id')->all(),
                'owned_device_ids' => McpServer::where('user_id', $u->id)->pluck('id')->all(),
                'skills' => DB::table('skill_grants')->where('user_id', $u->id)->pluck('skill_name')->all(),
            ];
        });

        return Inertia::render('Admin/Accounts', [
            'users' => $users,
            'devices' => $devices,
            'skills' => $skills,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'max:200'],
            'role' => ['required', 'in:admin,user'],
        ]);
        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'status' => 'active',
            'caps' => $data['role'] === 'admin'
                ? ['all_devices' => true, 'all_skills' => true, 'memory' => true]
                : ['all_devices' => false, 'all_skills' => false, 'memory' => true],
        ]);

        return back()->with('flash', ['success' => '帳號已建立。']);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate([
            'role' => ['required', 'in:admin,user'],
            'status' => ['required', 'in:active,disabled'],
            'caps' => ['array'],
            'caps.all_devices' => ['boolean'],
            'caps.all_skills' => ['boolean'],
            'caps.memory' => ['boolean'],
            'caps.local' => ['boolean'],
            'notify' => ['array'],
            'notify.tg_chat_id' => ['nullable', 'string', 'max:64'],
            'notify.line_to' => ['nullable', 'string', 'max:128'],
        ]);
        // 不可把自己（最後一個 admin）降級/停用，避免鎖死
        if ($user->isAdmin() && ($data['role'] !== 'admin' || $data['status'] !== 'active')
            && User::where('role', 'admin')->where('status', 'active')->count() <= 1) {
            return back()->with('flash', ['error' => '不能降級/停用最後一個管理員。']);
        }
        $user->update([
            'role' => $data['role'],
            'status' => $data['status'],
            'caps' => $data['caps'] ?? $user->caps,
            'notify' => $data['notify'] ?? $user->notify,
        ]);

        return back()->with('flash', ['success' => '帳號已更新。']);
    }

    public function setDevices(Request $request, User $user): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate(['device_ids' => ['array'], 'device_ids.*' => ['integer']]);
        DB::table('device_grants')->where('user_id', $user->id)->delete();
        foreach (array_unique($data['device_ids'] ?? []) as $id) {
            DB::table('device_grants')->insert(['user_id' => $user->id, 'mcp_server_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('flash', ['success' => '裝置授權已更新。']);
    }

    public function setSkills(Request $request, User $user): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate(['skills' => ['array'], 'skills.*' => ['string']]);
        DB::table('skill_grants')->where('user_id', $user->id)->delete();
        foreach (array_unique($data['skills'] ?? []) as $name) {
            DB::table('skill_grants')->insert(['user_id' => $user->id, 'skill_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('flash', ['success' => 'Skill 授權已更新。']);
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->gate($request);
        $data = $request->validate(['password' => ['required', 'string', 'min:6', 'max:200']]);
        $user->update(['password' => Hash::make($data['password'])]);

        return back()->with('flash', ['success' => '密碼已重設。']);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->gate($request);
        if ($user->id === $request->user()->id) {
            return back()->with('flash', ['error' => '不能刪除自己。']);
        }
        if ($user->isAdmin() && User::where('role', 'admin')->count() <= 1) {
            return back()->with('flash', ['error' => '不能刪除最後一個管理員。']);
        }
        DB::table('device_grants')->where('user_id', $user->id)->delete();
        DB::table('skill_grants')->where('user_id', $user->id)->delete();
        $user->delete();

        return back()->with('flash', ['success' => '帳號已刪除。']);
    }
}
