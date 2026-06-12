<?php

namespace App\Http\Controllers;

use App\Pai\Agent\PersonaProfiles;
use App\Pai\Skills\SkillRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agent Profiles（人格 / 模式）管理：每個帳號管理自己的 profiles。
 * 每個 profile = 人格(soul) + 可用工具白名單(tools) + 行為約束(constraints)，可隨時啟用切換。
 */
class AgentProfilesController extends Controller
{
    public function __construct(private readonly PersonaProfiles $profiles) {}

    public function index(Request $request, SkillRegistry $registry): Response
    {
        $uid = $request->user()?->id;
        // 可選的工具清單（builtin 名 + 去重後的 mcp base）給白名單勾選
        $tools = collect($registry->dedupedSkills(null))
            ->map(fn ($s) => str_starts_with($s->name(), 'mcp__') ? (explode('__', $s->name())[2] ?? $s->name()) : $s->name())
            ->unique()->sort()->values();

        return Inertia::render('Agent/Profiles', [
            'profiles' => $this->profiles->all($uid),
            'active' => $this->profiles->active($uid)['name'] ?? '',
            'tools' => $tools,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $uid = $request->user()?->id;
        $data = $request->validate([
            'profiles' => ['array'],
            'profiles.*.name' => ['required', 'string', 'max:40'],
            'profiles.*.soul' => ['nullable', 'string', 'max:4000'],
            'profiles.*.constraints' => ['nullable', 'string', 'max:2000'],
            'profiles.*.tools' => ['nullable'],          // 'all' 或 字串陣列
            'active' => ['nullable', 'string', 'max:40'],
        ]);
        $this->profiles->save($uid, $data['profiles'] ?? []);
        if (! empty($data['active'])) {
            $this->profiles->switchTo($uid, $data['active']);
        }

        return back()->with('flash', ['success' => '人格/模式已儲存。']);
    }

    public function activate(Request $request): RedirectResponse
    {
        $uid = $request->user()?->id;
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);
        $ok = $this->profiles->switchTo($uid, $data['name']);

        return back()->with('flash', $ok !== null
            ? ['success' => "已啟用「{$ok}」"]
            : ['error' => '找不到該人格']);
    }
}
