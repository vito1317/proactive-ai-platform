<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Automation\Scene;
use App\Pai\Skills\Skill;

/** 列出/刪除情境模式。delete=要刪的模式名（留空＝只列出）。 */
class ListScenesSkill implements Skill
{
    public function name(): string
    {
        return 'list-scenes';
    }

    public function description(): string
    {
        return '列出已建立的情境模式（「我有哪些模式」）；帶 delete=名稱 可刪除該模式（「刪掉睡覺模式」）。';
    }

    public function parameters(): array
    {
        return [
            'delete' => '（選填）要刪除的模式名稱；留空＝列出全部',
        ];
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
        $del = trim(str_replace('模式', '', (string) ($args['delete'] ?? '')));
        if ($del !== '') {
            $n = Scene::where('user_id', $uid)->where('name', $del)->delete();

            return $n > 0 ? "🗑️ 已刪除「{$del}」模式。" : "沒有「{$del}」這個模式。";
        }
        $scenes = Scene::where('user_id', $uid)->orderBy('name')->get();
        if ($scenes->isEmpty()) {
            return '還沒有任何情境模式。說「幫我建一個睡覺模式：關燈前念晚安、設明天七點鬧鐘」就能建立。';
        }
        $lines = $scenes->map(function ($s) {
            $acts = collect((array) $s->actions)->map(fn ($a) => $a['type'] ?? '?')->implode('→');

            return "・{$s->name}模式（{$acts}，用過 {$s->run_count} 次）";
        })->implode("\n");

        return "🎬 情境模式：\n{$lines}\n說「○○模式」即可執行。";
    }
}
