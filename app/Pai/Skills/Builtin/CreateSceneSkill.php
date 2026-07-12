<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Automation\Scene;
use App\Pai\Skills\Skill;

/**
 * 建立/更新「情境模式」：把多個動作打包成一句「○○模式」可觸發的組合。
 * 例：睡覺模式＝念晚安＋交代 agent 設明早鬧鐘＋通知明天第一件事。
 */
class CreateSceneSkill implements Skill
{
    public function name(): string
    {
        return 'create-scene';
    }

    public function description(): string
    {
        return '建立或更新「情境模式」（一句「○○模式」執行一串動作）。'
            .'name=模式名（不含「模式」二字，如 睡覺/上班/回家）；actions=JSON 動作陣列，格式同自動化：'
            .'[{"type":"speak","text":"晚安"},{"type":"notify","text":"…"},{"type":"agent","instruction":"幫我設明天7點的鬧鐘"},{"type":"open_map","place":"公司"}]。'
            .'使用者說「幫我建一個睡覺模式：…」時用；之後說「睡覺模式」即執行。同名會覆蓋更新。';
    }

    public function parameters(): array
    {
        return [
            'name' => '模式名稱（不含「模式」二字）',
            'actions' => '動作陣列 JSON（speak/notify/agent/open_map）',
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
        $name = trim(str_replace(['模式', ' '], '', (string) ($args['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 10) {
            return '模式名稱要 1~10 個字（不含「模式」），例如：睡覺、上班、回家。';
        }
        $raw = $args['actions'] ?? '';
        $actions = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($actions) || $actions === []) {
            return 'actions 要是動作陣列 JSON（speak/notify/agent/open_map），至少一個動作。';
        }
        $scene = Scene::updateOrCreate(
            ['user_id' => $uid, 'name' => $name],
            ['actions' => array_values($actions)]
        );

        return "✅ 情境模式「{$name}」已".($scene->wasRecentlyCreated ? '建立' : '更新')
            .'（'.count($actions).' 個動作）。之後說「'.$name.'模式」就會執行；「列出模式」可查看。';
    }
}
