<?php

namespace App\Pai\Cognition;

use App\Pai\Domains\DomainPack;
use App\Pai\Perception\PaiEvent;

/**
 * ReAct 迴圈的可變工作脈絡：觸發事件、領域包，與累積的發現/建議動作。
 * 工具透過它讀取上下文並寫回結果。
 */
final class AgentContext
{
    /** @var list<string> */
    public array $findings = [];

    /** @var list<array{action: string, rationale: string, risk: string, payload: array, confidence: float}> */
    public array $actions = [];

    /** @var list<array{to: string, task: string, artifact: array}> 跨域 A2A 交辦 */
    public array $handoffs = [];

    public ?string $summary = null;

    public bool $finished = false;

    public function __construct(
        public readonly PaiEvent $event,
        public readonly DomainPack $pack,
    ) {}

    public function addFinding(string $finding): void
    {
        $this->findings[] = $finding;
    }

    public function addAction(string $action, string $rationale, string $risk, array $payload = [], float $confidence = 0.7): void
    {
        $this->actions[] = [
            'action' => $action,
            'rationale' => $rationale,
            'risk' => $risk,
            'payload' => $payload,
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    public function addHandoff(string $to, string $task, array $artifact): void
    {
        $this->handoffs[] = ['to' => $to, 'task' => $task, 'artifact' => $artifact];
    }
}
