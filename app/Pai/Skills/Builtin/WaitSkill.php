<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;

/** 等待工具：暫停指定秒數後再繼續（等頁面/App 載入、等狀態變化前的間隔）。 */
class WaitSkill implements Skill
{
    public function name(): string
    {
        return 'wait';
    }

    public function description(): string
    {
        return '等待指定秒數後再繼續下一步（例：開啟網頁/App 後等它載入、輪詢前的間隔）。seconds 上限 180';
    }

    public function parameters(): array
    {
        return ['seconds' => '要等待的秒數（1~180）'];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $s = (int) ($args['seconds'] ?? 0);
        $s = max(1, min(180, $s));
        sleep($s);

        return "已等待 {$s} 秒。";
    }
}
