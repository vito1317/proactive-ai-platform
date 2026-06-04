<?php

namespace App\Pai\Domains;

/**
 * 一個已解析、已驗證的領域包 (Domain Pack)。
 *
 * 對應 docs/SPEC.md v1 的 manifest 契約。這是不可變的值物件——
 * 平台核心透過它得知一個領域的觸發源 (L1)、工具白名單 (L4)、
 * 子智能體 (L3)、記憶命名空間 (L2) 與風險策略 (L5)。
 *
 * 建構請走 {@see DomainPack::fromArray()}（會先經 {@see DomainPackValidator}）。
 */
final readonly class DomainPack
{
    /**
     * @param  array{events: string[], cron: string[]}  $triggers
     * @param  list<array{uri: string, perms: string[], risk: string}>  $tools
     * @param  list<array{name: string, role: string}>  $roster
     * @param  string[]  $playbooks
     * @param  list<array{type: string, source: string}>  $knowledge
     * @param  string[]  $hitlRequired
     * @param  array<string, string>  $rateLimits
     * @param  array<string, string>  $slo
     */
    public function __construct(
        public string $domain,
        public string $coordinator,
        public string $description,
        public array $triggers,
        public array $tools,
        public string $topology,
        public array $roster,
        public array $playbooks,
        public string $memoryNamespace,
        public array $knowledge,
        public string $autonomy,
        public array $hitlRequired,
        public array $rateLimits,
        public string $outputContract,
        public array $slo,
    ) {}

    /**
     * 從已驗證的 manifest 陣列建構。
     *
     * @param  array<string, mixed>  $m
     */
    public static function fromArray(array $m): self
    {
        $tools = array_map(static fn (array $t): array => [
            'uri' => $t['uri'],
            'perms' => $t['perms'],
            'risk' => $t['risk'] ?? 'low',
        ], $m['tools']);

        return new self(
            domain: $m['domain'],
            coordinator: $m['coordinator'],
            description: $m['description'],
            triggers: [
                'events' => $m['triggers']['events'] ?? [],
                'cron' => $m['triggers']['cron'] ?? [],
            ],
            tools: $tools,
            topology: $m['agents']['topology'],
            roster: $m['agents']['roster'],
            playbooks: $m['playbooks'] ?? [],
            memoryNamespace: $m['memory']['namespace'],
            knowledge: $m['memory']['knowledge'],
            autonomy: $m['risk_policy']['autonomy'],
            hitlRequired: $m['risk_policy']['hitl_required'] ?? [],
            rateLimits: $m['risk_policy']['rate_limits'] ?? [],
            outputContract: $m['contracts']['output'],
            slo: $m['slo'] ?? [],
        );
    }

    /** 此領域訂閱的事件主題 (L1)。 */
    public function eventTopics(): array
    {
        return $this->triggers['events'];
    }

    /** 此領域的 cron 觸發定義 (L1)。 */
    public function cronTriggers(): array
    {
        return $this->triggers['cron'];
    }

    /** 標記為 high 風險的工具 (L4 / L5)。 */
    public function highRiskTools(): array
    {
        return array_values(array_filter(
            $this->tools,
            static fn (array $t): bool => $t['risk'] === 'high',
        ));
    }

    /**
     * 此動作是否被明確列入 hitl_required（必須人類核准）。
     *
     * 注意：完整的自治階段門檻（copilot 一律擋 write/exec 等）由
     * L5 護欄層依 {@see autonomy} 判定，不在此值物件內。
     */
    public function isHitlAction(string $action): bool
    {
        return in_array($action, $this->hitlRequired, true);
    }

    /** 子智能體名稱清單。 */
    public function agentNames(): array
    {
        return array_map(static fn (array $a): string => $a['name'], $this->roster);
    }

    /** 給前端 / API 的精簡序列化。 */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'coordinator' => $this->coordinator,
            'description' => $this->description,
            'autonomy' => $this->autonomy,
            'topology' => $this->topology,
            'agents' => $this->agentNames(),
            'events' => $this->eventTopics(),
            'tools' => array_map(static fn (array $t): string => $t['uri'], $this->tools),
            'high_risk_tools' => array_map(static fn (array $t): string => $t['uri'], $this->highRiskTools()),
            'hitl_required' => $this->hitlRequired,
        ];
    }
}
