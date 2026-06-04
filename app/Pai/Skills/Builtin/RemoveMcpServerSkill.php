<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Mcp\McpManager;
use App\Pai\Skills\Skill;

/** 移除一個已接入的 MCP server。高風險。 */
class RemoveMcpServerSkill implements Skill
{
    public function __construct(private readonly McpManager $manager) {}

    public function name(): string
    {
        return 'remove-mcp-server';
    }

    public function description(): string
    {
        return '移除一個已接入的 MCP server（其工具將不再可用）';
    }

    public function parameters(): array
    {
        return ['name' => '要移除的 server 代號'];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $name = (string) ($args['name'] ?? '');

        return $this->manager->remove($name)
            ? "已移除 MCP server「{$name}」。"
            : "找不到 MCP server「{$name}」。";
    }
}
