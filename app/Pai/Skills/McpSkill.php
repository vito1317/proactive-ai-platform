<?php

namespace App\Pai\Skills;

use App\Pai\Mcp\McpClient;
use App\Pai\Mcp\McpServer;

/**
 * 把一個 MCP server 的工具包成「對話技能」，讓即時聊天的 agentic 迴圈
 * 也能選用（與 ReAct 的 McpTool 平行）。action 名稱：mcp__<server>__<tool>。
 *
 * 預設視為高風險（多半是遠端執行/改狀態）；唯讀型（list/status/logs/get/read…）降為低風險。
 */
class McpSkill implements Skill
{
    public function __construct(
        private readonly McpClient $client,
        private readonly McpServer $server,
        private readonly array $def,
    ) {}

    public function name(): string
    {
        return 'mcp__'.$this->server->name.'__'.$this->def['name'];
    }

    public function description(): string
    {
        return "[MCP:{$this->server->name}] ".($this->def['description'] ?? '（無說明）');
    }

    public function parameters(): array
    {
        $props = $this->def['inputSchema']['properties'] ?? [];
        $out = [];
        foreach ($props as $key => $meta) {
            $out[$key] = is_array($meta) ? (string) ($meta['description'] ?? ($meta['type'] ?? '')) : (string) $meta;
        }

        return $out;
    }

    public function isHighRisk(): bool
    {
        // 唯讀型工具降為低風險，其餘（exec/spawn/kill/write…）視為高風險
        $tool = strtolower((string) ($this->def['name'] ?? ''));
        foreach (['list', 'status', 'logs', 'get', 'read', 'show', 'describe', 'search', 'query', 'health'] as $safe) {
            if (str_contains($tool, $safe)) {
                return false;
            }
        }

        return true;
    }

    public function run(array $args): string
    {
        $res = $this->client->callTool($this->server->url, $this->server->headers ?? [], $this->def['name'], $args);

        return $res['ok']
            ? (string) ($res['text'] ?? '（無輸出）')
            : 'MCP 工具呼叫失敗：'.($res['error'] ?? '未知');
    }
}
