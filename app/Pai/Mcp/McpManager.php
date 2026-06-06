<?php

namespace App\Pai\Mcp;

use App\Pai\Cognition\Tool;
use Illuminate\Support\Collection;

/**
 * MCP server 管理：新增（含驗證 + 工具快取）/ 移除 / 測試，
 * 並把所有已啟用 server 的工具包成 ReAct Tool 提供給認知大腦（L4）。
 */
class McpManager
{
    public function __construct(private readonly McpClient $client) {}

    /**
     * 新增/更新一個 MCP server（會連線驗證並快取工具清單）。
     *
     * @return array{ok: bool, message: string, tools?: array}
     */
    public function add(string $name, string $url, array $headers = []): array
    {
        $name = trim($name);
        if ($name === '' || ! preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'message' => '需要有效的 name 與 http(s) url。'];
        }
        $res = $this->client->listTools($url, $headers);
        if (! $res['ok']) {
            // 仍記錄但停用，方便使用者修正
            McpServer::updateOrCreate(['name' => $name],
                ['url' => $url, 'headers' => $headers, 'enabled' => false, 'last_error' => $res['error'] ?? '連線失敗']);

            return ['ok' => false, 'message' => "已記錄 MCP server「{$name}」但連線/列工具失敗：".($res['error'] ?? '未知').'（已暫時停用，修正後可用 test 重試）'];
        }
        $tools = $res['tools'];
        McpServer::updateOrCreate(['name' => $name],
            ['url' => $url, 'headers' => $headers, 'enabled' => true, 'tools' => $tools, 'last_error' => null]);

        $names = collect($tools)->pluck('name')->implode('、');

        return ['ok' => true, 'message' => "已接入 MCP server「{$name}」✅，取得 ".count($tools)." 個工具：{$names}", 'tools' => $tools];
    }

    public function remove(string $name): bool
    {
        return (bool) McpServer::where('name', $name)->delete();
    }

    public function all(): Collection
    {
        return McpServer::orderBy('name')->get();
    }

    /** 重新連線測試並刷新工具快取。 */
    public function test(string $name): array
    {
        $server = McpServer::where('name', $name)->first();
        if (! $server) {
            return ['ok' => false, 'message' => "找不到 MCP server「{$name}」。"];
        }
        $res = $this->client->listTools($server->url, $server->headers ?? []);
        // 成功 → 啟用並刷新工具；失敗 → 只記錯誤，不自動停用
        // （隧道漂移的短暫空窗常觸發這裡，停用會讓節點「斷一下就永久消失」，恢復後也回不來）
        $server->update($res['ok']
            ? ['enabled' => true, 'tools' => $res['tools'], 'last_error' => null]
            : ['last_error' => $res['error'] ?? '連線失敗']);

        return $res['ok']
            ? ['ok' => true, 'message' => "「{$name}」正常，".count($res['tools']).' 個工具已更新。']
            : ['ok' => false, 'message' => "「{$name}」連線失敗：".($res['error'] ?? '未知')];
    }

    /**
     * 所有已啟用 server 的工具（包成 ReAct Tool，供認知大腦使用）。
     *
     * @return list<Tool>
     */
    public function tools(): array
    {
        $out = [];
        foreach (McpServer::where('enabled', true)->get() as $server) {
            foreach (($server->tools ?? []) as $tool) {
                if (isset($tool['name'])) {
                    $out[] = new McpTool($this->client, $server, $tool);
                }
            }
        }

        return $out;
    }
}
