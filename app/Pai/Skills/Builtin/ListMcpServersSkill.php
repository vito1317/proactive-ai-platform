<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Mcp\McpManager;
use App\Pai\Skills\Skill;

/** 列出已接入的 MCP server 及其工具。低風險。 */
class ListMcpServersSkill implements Skill
{
    public function __construct(private readonly McpManager $manager) {}

    public function name(): string
    {
        return 'list-mcp-servers';
    }

    public function description(): string
    {
        return '列出已接入的 MCP 工具伺服器、啟用狀態與其提供的工具';
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
        $servers = $this->manager->all();
        if ($servers->isEmpty()) {
            return '目前沒有接入任何 MCP server。可以說「接上 MCP server，名稱 X，URL 是 …」來新增。';
        }
        $lines = $servers->map(function ($s) {
            $state = $s->enabled ? '✅' : '🔕';
            $tools = collect($s->tools ?? [])->pluck('name')->implode('、') ?: '（無）';
            $err = $s->last_error ? "；最後錯誤：{$s->last_error}" : '';

            return "{$state} {$s->name}（{$s->url}）工具：{$tools}{$err}";
        });

        return "已接入的 MCP server：\n".$lines->implode("\n");
    }
}
