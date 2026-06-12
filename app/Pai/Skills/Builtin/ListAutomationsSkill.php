<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Automation\Automation;
use App\Pai\Skills\Skill;

/** 列出目前帳號的自動化流程。 */
class ListAutomationsSkill implements Skill
{
    public function name(): string
    {
        return 'list-automations';
    }

    public function description(): string
    {
        return '列出我建立的自動化流程（含啟用狀態與觸發方式）。';
    }

    public function parameters(): array
    {
        return [];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $uid = Tenant::id();
        if ($uid === null) {
            return '無法判斷帳號。';
        }
        $rows = Automation::where('user_id', $uid)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return '你還沒有任何自動化流程。可說「幫我建立一個…」讓我建。';
        }

        return $rows->map(function (Automation $a) {
            $t = (array) ($a->spec['trigger'] ?? []);
            $trig = match ($t['type'] ?? '') {
                'daily' => "每天 {$t['at']}",
                'interval' => "每 {$t['every_min']} 分",
                'unlock' => '早晨解鎖時',
                default => '—',
            };

            return ($a->enabled ? '🟢' : '⚪')." #{$a->id} {$a->name}（{$trig}）";
        })->implode("\n");
    }
}
