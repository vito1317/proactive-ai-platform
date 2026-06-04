<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Chat\SlashCommands;
use App\Pai\Skills\Skill;

/** 移除一個自訂斜線指令。高風險。 */
class RemoveCommandSkill implements Skill
{
    public function __construct(private readonly SlashCommands $commands) {}

    public function name(): string
    {
        return 'remove-command';
    }

    public function description(): string
    {
        return '移除一個自訂斜線指令';
    }

    public function parameters(): array
    {
        return ['name' => '要移除的指令名稱（不含斜線）'];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $name = (string) ($args['name'] ?? '');

        return $this->commands->remove($name)
            ? "已移除自訂指令 /{$name}。"
            : "找不到自訂指令 /{$name}。";
    }
}
