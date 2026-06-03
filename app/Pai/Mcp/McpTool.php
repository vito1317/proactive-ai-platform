<?php

namespace App\Pai\Mcp;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/**
 * 把一個 MCP server 的工具包成 ReAct Tool（L4）。
 * action 名稱用 mcp__<server>__<tool>，避免與內建工具衝突。
 */
class McpTool implements Tool
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
        $desc = $this->def['description'] ?? '（無說明）';
        $props = $this->def['inputSchema']['properties'] ?? [];
        $args = $props ? '；參數：'.implode('、', array_keys($props)) : '';

        return "[MCP:{$this->server->name}] {$desc}{$args}";
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $res = $this->client->callTool($this->server->url, $this->server->headers ?? [], $this->def['name'], $input);

        return $res['ok']
            ? ToolResult::ok($res['text'])
            : ToolResult::fail('MCP 工具呼叫失敗：'.($res['error'] ?? '未知'));
    }
}
