<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Mcp\McpManager;
use App\Pai\Skills\Skill;

/** 接入一個 MCP server（其工具會自動成為平台/協調者可用工具）。高風險。 */
class AddMcpServerSkill implements Skill
{
    public function __construct(private readonly McpManager $manager) {}

    public function name(): string
    {
        return 'add-mcp-server';
    }

    public function description(): string
    {
        return '接入一個 MCP 工具伺服器（Streamable HTTP），接入後其工具會成為 AI 可用工具';
    }

    public function parameters(): array
    {
        return [
            'name' => 'server 代號（英數，工具名前綴）',
            'url' => 'MCP server 的 http(s) 端點',
            'headers' => '認證標頭（選填）；可給 JSON 物件，或 "Authorization: Bearer xxx" 字串；密鑰可用 {{vault:NAME}} 佔位',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $headers = $this->parseHeaders($args['headers'] ?? null);

        return $this->manager->add(
            (string) ($args['name'] ?? ''),
            (string) ($args['url'] ?? ''),
            $headers,
        )['message'];
    }

    /** 接受 JSON 物件、"Key: Value" 字串，或已是陣列。 */
    private function parseHeaders(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
        if (str_contains($raw, ':')) {
            [$k, $v] = explode(':', $raw, 2);

            return [trim($k) => trim($v)];
        }

        return [];
    }
}
