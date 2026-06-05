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
        return '列出所有節點 / gateway / MCP 伺服器的「即時連線狀態」（線上🟢/離線🔴、延遲、工具）。問「有哪些節點 / gateway 狀態 / 節點線上嗎」就用這個（即時 ping，不可憑空回答）';
    }

    public function parameters(): array
    {
        return [
            'ping' => '是否即時連線測試各節點（true/false，預設 true）',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $servers = $this->manager->all();
        if ($servers->isEmpty()) {
            return '目前沒有接入任何 MCP 節點。可以說「接上 MCP server，名稱 X，URL 是 …」來新增。';
        }
        $ping = filter_var($args['ping'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $lines = $servers->map(function ($s) use ($ping) {
            $tools = collect($s->tools ?? [])->pluck('name')->implode('、') ?: '（無）';
            if (! $ping) {
                $state = $s->enabled ? '✅ 已啟用' : '🔕 停用';

                return "{$state} {$s->name}（{$s->url}）工具：{$tools}";
            }
            // 即時連線測試
            $t0 = microtime(true);
            $res = $this->manager->test($s->name);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            if ($res['ok'] ?? false) {
                return "🟢 已連線 {$s->name}（{$s->url}）· {$ms}ms · 工具：{$tools}";
            }

            return "🔴 連不到 {$s->name}（{$s->url}）· {$ms}ms · 原因：".($res['message'] ?? '未知');
        });

        return "MCP 節點連線狀態：\n".$lines->implode("\n");
    }
}
