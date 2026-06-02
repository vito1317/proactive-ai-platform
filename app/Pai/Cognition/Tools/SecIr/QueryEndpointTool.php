<?php

namespace App\Pai\Cognition\Tools\SecIr;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;
use App\Pai\Security\EgressGateway;
use App\Pai\Security\SecretRef;
use Throwable;

/**
 * 查詢端點 (EDR) 遙測。若設定了真實 EDR 端點，會經 EgressGateway
 * 在網路層注入 {{vault:edr_token}}（智能體不持有憑證 ← P2 零信任）；
 * 未設定則回傳模擬遙測，方便本機示範。
 */
final class QueryEndpointTool implements Tool
{
    public function __construct(
        private readonly EgressGateway $egress,
        private readonly ?string $edrUrl,
    ) {}

    public function name(): string
    {
        return 'query_endpoint';
    }

    public function description(): string
    {
        return '查詢主機的 EDR 遙測。action_input: {"host":"10.0.0.5"}。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $host = trim((string) ($input['host'] ?? ''));
        if ($host === '') {
            $host = (string) ($ctx->event->payload['host'] ?? 'unknown');
        }

        if ($this->edrUrl) {
            try {
                $resp = $this->egress->client()
                    ->withHeaders(['Authorization' => 'Bearer '.SecretRef::placeholder('edr_token')])
                    ->get($this->edrUrl, ['host' => $host]);

                return ToolResult::ok("EDR 回應 ({$host})：\n".mb_substr($resp->body(), 0, 800));
            } catch (Throwable $e) {
                return ToolResult::fail("EDR 查詢失敗：{$e->getMessage()}");
            }
        }

        // 模擬遙測（依事件內容給出合理的示範資料）
        $sim = [
            'host' => $host,
            'processes' => ['suspicious.exe (unsigned)', 'powershell -enc ...'],
            'recent_logins' => 14,
            'outbound_connections' => ['185.0.0.7:443 (unknown)'],
            'note' => '（模擬遙測；設定 PAI_SECIR_EDR_URL 可接真實 EDR）',
        ];

        return ToolResult::ok("EDR 遙測 ({$host})：\n".json_encode($sim, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
