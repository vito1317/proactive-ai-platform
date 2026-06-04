<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;

/** 列出所有領域包（含停用者）。低風險。 */
class ListDomainsSkill implements Skill
{
    public function name(): string
    {
        return 'list-domains';
    }

    public function description(): string
    {
        return '列出目前所有監控/自動化領域包及其啟用狀態';
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
        $lines = [];
        foreach (glob(base_path('packs/*.yaml*')) as $file) {
            $disabled = str_ends_with($file, '.disabled');
            $name = basename($file, $disabled ? '.yaml.disabled' : '.yaml');
            $lines[$name] = '・'.$name.'：'.($disabled ? '🔕 停用' : '✅ 啟用');
        }

        return $lines === [] ? '目前沒有領域包。' : "領域包清單：\n".implode("\n", $lines);
    }
}
