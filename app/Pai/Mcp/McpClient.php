<?php

namespace App\Pai\Mcp;

use App\Pai\Security\EgressGateway;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * 極簡 MCP 客戶端（JSON-RPC 2.0 over Streamable HTTP）。
 * 走 EgressGateway，故認證標頭可用 {{vault:NAME}} 佔位、由網路層注入真憑證。
 * 支援回應為 application/json 或 text/event-stream（SSE）兩種格式。
 */
class McpClient
{
    private const PROTOCOL = '2024-11-05';

    public function __construct(private readonly EgressGateway $egress) {}

    /**
     * 列出某 server 的工具。
     *
     * @return array{ok: bool, tools?: array<int,array>, error?: string}
     */
    public function listTools(string $url, array $headers): array
    {
        // 反向節點：工具清單在註冊時由手機帶上、存於 ReverseBus
        if (str_starts_with($url, 'reverse://')) {
            return ReverseBus::tools(substr($url, strlen('reverse://')));
        }
        try {
            [$resp, $init, $sid] = $this->post($url, $headers, $this->initPayload(), null);
            if ($resp->failed()) {
                return ['ok' => false, 'error' => "初始化 HTTP {$resp->status()}"];
            }
            if (isset($init['error'])) {
                return ['ok' => false, 'error' => (string) ($init['error']['message'] ?? 'initialize 失敗')];
            }
            $this->notifyInitialized($url, $headers, $sid);

            [, $list] = $this->post($url, $headers, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sid);
            $tools = $list['result']['tools'] ?? null;
            if (! is_array($tools)) {
                return ['ok' => false, 'error' => (string) ($list['error']['message'] ?? '無有效的 tools/list 回應')];
            }

            return ['ok' => true, 'tools' => $tools];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 呼叫某工具。
     *
     * @return array{ok: bool, text?: string, error?: string}
     */
    public function callTool(string $url, array $headers, string $tool, array $args): array
    {
        // 反向節點（如 Android，無公網/cloudflared）：把呼叫放佇列等手機 poll 執行、回傳結果
        if (str_starts_with($url, 'reverse://')) {
            return ReverseBus::call(substr($url, strlen('reverse://')), $tool, $args);
        }
        try {
            [$resp, $init, $sid] = $this->post($url, $headers, $this->initPayload(), null);
            if ($resp->failed() || isset($init['error'])) {
                return ['ok' => false, 'error' => (string) ($init['error']['message'] ?? "初始化 HTTP {$resp->status()}")];
            }
            $this->notifyInitialized($url, $headers, $sid);

            [, $res] = $this->post($url, $headers, [
                'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => (object) $args],
            ], $sid);

            if (isset($res['error'])) {
                return ['ok' => false, 'error' => (string) ($res['error']['message'] ?? 'tools/call 失敗')];
            }
            $content = $res['result']['content'] ?? [];
            $text = collect(is_array($content) ? $content : [])
                ->map(fn ($c) => is_array($c) ? ($c['text'] ?? json_encode($c, JSON_UNESCAPED_UNICODE)) : (string) $c)
                ->implode("\n");

            return ['ok' => true, 'text' => $text !== '' ? $text : '（工具沒有回傳內容）'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function initPayload(): array
    {
        return [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'PAI', 'version' => '1.0'],
            ],
        ];
    }

    private function notifyInitialized(string $url, array $headers, ?string $sid): void
    {
        try {
            $this->post($url, $headers, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sid);
        } catch (Throwable) {
            // 通知失敗不影響後續呼叫
        }
    }

    /**
     * 送一個 JSON-RPC，回傳 [response, decodedBody, sessionId]。
     *
     * @return array{0: Response, 1: array, 2: ?string}
     */
    private function post(string $url, array $headers, array $payload, ?string $sid): array
    {
        $headers = array_merge($headers, [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ]);
        if ($sid) {
            $headers['Mcp-Session-Id'] = $sid;
        }

        // connectTimeout 短（隧道死掉/換網址時快速失敗，不要卡滿 timeout）；總 timeout 留給實際指令。
        // 強制 IPv4：本機對 trycloudflare 只解析到 IPv6 且 IPv6 對外不通 → 不強制會 6ms 立即失敗（cURL 7）。
        $resp = $this->egress->client()->withHeaders($headers)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->connectTimeout(5)->timeout(20)->post($url, $payload);
        $newSid = $resp->header('Mcp-Session-Id') ?: $sid;

        return [$resp, $this->decode($resp->body()), $newSid];
    }

    /** 解析回應：SSE（取最後一個 data: JSON）或純 JSON。 */
    private function decode(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }
        if (str_contains($body, 'data:')) {
            $json = null;
            foreach (preg_split('/\r?\n/', $body) as $line) {
                if (str_starts_with(trim($line), 'data:')) {
                    $json = trim(substr(trim($line), 5));
                }
            }
            $body = $json ?? $body;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
