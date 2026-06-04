<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Chat\SlashCommands;
use App\Pai\Skills\Skill;

/** 列出已定義的自訂斜線指令。低風險。 */
class ListCommandsSkill implements Skill
{
    public function __construct(private readonly SlashCommands $commands) {}

    public function name(): string
    {
        return 'list-commands';
    }

    public function description(): string
    {
        return '列出所有自訂斜線指令（/name）及其內容';
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
        $all = $this->commands->all();
        if ($all->isEmpty()) {
            return '目前沒有自訂指令。可以說「新增指令 /waf，內容是 …」來建立。';
        }

        return "自訂斜線指令：\n".$all->map(fn ($c) => "・/{$c->name}：".mb_substr($c->body, 0, 60))->implode("\n");
    }
}
