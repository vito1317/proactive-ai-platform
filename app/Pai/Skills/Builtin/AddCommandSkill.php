<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Chat\SlashCommands;
use App\Pai\Skills\Skill;

/** 新增/更新自訂斜線指令（/name → 展開成內容，聊天室/TG/LINE 共用）。高風險。 */
class AddCommandSkill implements Skill
{
    public function __construct(private readonly SlashCommands $commands) {}

    public function name(): string
    {
        return 'add-command';
    }

    public function description(): string
    {
        return '新增或更新一個自訂斜線指令：之後在聊天室或 TG/LINE 打 /名稱 就會展開成你定義的內容並執行';
    }

    public function parameters(): array
    {
        return [
            'name' => '指令名稱（不含斜線，英數，如 waf）',
            'body' => '指令展開後的內容（給 AI 的指示；可用 {{args}} 代入使用者附帶文字）',
            'description' => '說明（選填，會顯示在 Telegram 指令選單）',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $name = (string) ($args['name'] ?? '');
        $body = (string) ($args['body'] ?? '');
        if ($name === '' || $body === '') {
            return '請提供指令名稱（name）與內容（body）。';
        }
        $cmd = $this->commands->add($name, $body, $args['description'] ?? null);

        return "已新增自訂指令 /{$cmd->name} ✅，內容：「".mb_substr($cmd->body, 0, 80).'」。之後在聊天室、Telegram、LINE 打 /'.$cmd->name.' 即可使用（TG 指令選單已同步）。';
    }
}
