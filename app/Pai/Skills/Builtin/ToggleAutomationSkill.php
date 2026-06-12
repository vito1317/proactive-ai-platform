<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Automation\Automation;
use App\Pai\Skills\Skill;

/** 啟用/停用/刪除某條自動化流程。 */
class ToggleAutomationSkill implements Skill
{
    public function name(): string
    {
        return 'toggle-automation';
    }

    public function description(): string
    {
        return '啟用 / 停用 / 刪除自動化流程。id=流程編號；action=enable|disable|delete。';
    }

    public function parameters(): array
    {
        return [
            'id' => '流程編號',
            'action' => 'enable｜disable｜delete',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $uid = Tenant::id();
        $auto = Automation::where('user_id', $uid)->find((int) ($args['id'] ?? 0));
        if ($auto === null) {
            return '找不到這條流程（或不是你的）。';
        }
        $action = (string) ($args['action'] ?? 'disable');
        if ($action === 'delete') {
            $auto->delete();

            return "🗑️ 已刪除流程「{$auto->name}」。";
        }
        $auto->enabled = $action === 'enable';
        $auto->save();

        return ($auto->enabled ? '🟢 已啟用' : '⚪ 已停用')."流程「{$auto->name}」。";
    }
}
