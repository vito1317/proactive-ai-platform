<?php

namespace App\Pai\Cognition;

use App\Pai\Cognition\Tools\FinishTool;
use App\Pai\Cognition\Tools\GetEventContextTool;
use App\Pai\Cognition\Tools\HandoffTool;
use App\Pai\Cognition\Tools\ProposeActionTool;
use App\Pai\Cognition\Tools\RecallMemoryTool;
use App\Pai\Cognition\Tools\RecordFindingTool;
use App\Pai\Domains\DomainPack;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Perception\PaiEvent;
use App\Pai\Settings\Settings;
use Throwable;

/**
 * L3 認知大腦：對一個事件，以領域協調者身分跑 ReAct 迴圈
 * （思考 → 行動 → 觀察），記錄發現、提出動作，再依 risk_policy /
 * autonomy 決定哪些動作自動執行、哪些送 L5 人類審核。
 */
class CognitiveEngine
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Settings $settings,
        private readonly DomainRegistry $registry,
        private readonly DomainToolset $toolset,
        private readonly \App\Pai\Action\ActionExecutor $executor,
        private readonly \App\Pai\Memory\MemoryStore $memory,
    ) {}

    /**
     * 基礎工具（所有協調者共用）+ 領域專屬工具。
     *
     * @return array<string, Tool> name => tool
     */
    private function tools(DomainPack $pack): array
    {
        $tools = [
            new GetEventContextTool,
            new RecallMemoryTool($this->memory),
            new RecordFindingTool,
            new ProposeActionTool,
            new HandoffTool($this->registry),
            new FinishTool,
            ...$this->toolset->for($pack->domain),
        ];
        $map = [];
        foreach ($tools as $t) {
            $map[$t->name()] = $t;
        }

        return $map;
    }

    /** 全新運行。 */
    public function run(PaiEvent $event, DomainPack $pack): AgentRun
    {
        $run = AgentRun::create([
            'event_id' => $event->id,
            'domain' => $pack->domain,
            'coordinator' => $pack->coordinator,
            'status' => RunStatus::Running,
        ]);

        return $this->execute($run, $event, $pack);
    }

    /**
     * 續跑一個中斷的運行（崩潰/逾時後精確重試）：重放已存步驟重建上下文，
     * 不重新呼叫 LLM，從中斷處繼續。
     */
    public function resume(AgentRun $run): AgentRun
    {
        $event = $run->event;
        $pack = $this->registry->get($run->domain);
        if ($event === null || $pack === null) {
            $run->update(['status' => RunStatus::Failed, 'error' => '無法續跑：缺事件或領域包']);

            return $run->refresh();
        }

        return $this->execute($run, $event, $pack);
    }

    private function execute(AgentRun $run, PaiEvent $event, DomainPack $pack): AgentRun
    {
        $ctx = new AgentContext($event, $pack);
        $tools = $this->tools($pack);
        $maxSteps = max(1, (int) $this->settings->get('react.max_steps', 6));

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($pack, $tools, $maxSteps)],
            ['role' => 'user', 'content' => $this->taskPrompt($event)],
        ];

        // 重放已持久化步驟（重建 ctx 與對話，零 LLM 呼叫）
        $steps = is_array($run->steps) ? $run->steps : [];
        $this->replay($steps, $ctx, $tools, $messages);
        $tokens = (int) $run->tokens;
        $startStep = $this->lastNumericStep($steps) + 1;

        try {
            for ($i = $startStep; $i <= $maxSteps && ! $ctx->finished; $i++) {
                $res = $this->llm->complete($messages);
                $tokens += (int) ($res['usage']['total_tokens'] ?? 0);

                $decision = LlmClient::extractJson($res['content']);
                $action = (string) ($decision['action'] ?? '');
                $input = is_array($decision['action_input'] ?? null) ? $decision['action_input'] : [];

                $tool = $tools[$action] ?? null;
                $observation = $tool === null
                    ? "未知的 action「{$action}」。可用：".implode(', ', array_keys($tools))
                    : $tool->run($input, $ctx)->observation;

                $steps[] = [
                    'step' => $i,
                    'thought' => (string) ($decision['thought'] ?? ''),
                    'reasoning' => mb_substr(trim($res['reasoning']), 0, 600),
                    'action' => $action,
                    'action_input' => $input,
                    'observation' => $observation,
                ];

                // 逐步持久化 → 崩潰後可從此處續跑
                $run->update(['steps' => $steps, 'tokens' => $tokens]);

                if ($ctx->finished) {
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => $res['content']];
                $messages[] = ['role' => 'user', 'content' => "Observation: {$observation}\n請輸出下一步的 JSON（若已完成請用 finish）。"];
            }

            if ($this->settings->get('react.reflect', true)
                && ($ctx->findings !== [] || $ctx->actions !== [])
                && ! $this->hasReflected($steps)) {
                $steps[] = $this->reflect($ctx, $tokens);
            }

            // A2A 跨域交辦：派發給目標領域（去重，重放安全）
            $this->dispatchHandoffs($event, $pack, $ctx);

            [$actions, $needHitl] = $this->gateActions($ctx, $pack);

            $run->update([
                'status' => $needHitl ? RunStatus::AwaitingHitl : RunStatus::Completed,
                'steps' => $steps,
                'findings' => $ctx->findings,
                'actions' => $actions,
                'summary' => $ctx->summary ?? '（無總結）',
                'tokens' => $tokens,
            ]);

            // L2：把本次處置寫入領域記憶，供未來事件語意檢索（去重，重放安全）
            $this->rememberRun($run, $event, $pack, $ctx);

            // L5：有待核准動作 → 推播給人類（去重，重放安全）
            if ($needHitl) {
                app(\App\Pai\Notify\PushNotifier::class)->hitlNeeded($run);
            }
        } catch (Throwable $e) {
            $run->update([
                'status' => RunStatus::Failed,
                'steps' => $steps,
                'error' => $e->getMessage(),
                'tokens' => $tokens,
            ]);
        }

        return $run->refresh();
    }

    /**
     * 重放已存步驟以重建 ctx（findings/actions/summary）與對話脈絡，不呼叫 LLM。
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, Tool>  $tools
     */
    private function replay(array $steps, AgentContext $ctx, array $tools, array &$messages): void
    {
        foreach ($steps as $s) {
            $action = $s['action'] ?? '';
            if ($action === 'reflect' || $action === '') {
                continue; // 反思為迴圈後步驟，不重放
            }
            // 重跑工具以重建 ctx 副作用（record_finding/propose_action/finish 皆為冪等附加）
            if (isset($tools[$action])) {
                $tools[$action]->run(is_array($s['action_input'] ?? null) ? $s['action_input'] : [], $ctx);
            }
            // 還原對話，讓續跑的 LLM 看得到先前脈絡
            $messages[] = ['role' => 'assistant', 'content' => json_encode([
                'thought' => $s['thought'] ?? '',
                'action' => $action,
                'action_input' => $s['action_input'] ?? [],
            ], JSON_UNESCAPED_UNICODE)];
            $messages[] = ['role' => 'user', 'content' => 'Observation: '.($s['observation'] ?? '')."\n請輸出下一步的 JSON（若已完成請用 finish）。"];
        }
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function lastNumericStep(array $steps): int
    {
        $max = 0;
        foreach ($steps as $s) {
            if (is_int($s['step'] ?? null)) {
                $max = max($max, $s['step']);
            }
        }

        return $max;
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function hasReflected(array $steps): bool
    {
        foreach ($steps as $s) {
            if (($s['action'] ?? null) === 'reflect') {
                return true;
            }
        }

        return false;
    }

    /**
     * 派發 A2A 跨域交辦：為每個 handoff 建立子事件並喚醒目標協調者。
     * 以「同 parent_event_id + task 的 a2a 子事件是否已存在」去重，確保重放不重複派發。
     */
    private function dispatchHandoffs(PaiEvent $event, DomainPack $pack, AgentContext $ctx): void
    {
        foreach ($ctx->handoffs as $h) {
            $exists = PaiEvent::query()
                ->where('source', 'a2a')
                ->where('domain', $h['to'])
                ->where('payload->parent_event_id', $event->id)
                ->where('payload->task', $h['task'])
                ->exists();
            if ($exists) {
                continue;
            }

            $child = PaiEvent::create([
                'source' => 'a2a',
                'topic' => 'a2a.task',
                'domain' => $h['to'],
                'intent' => 'a2a-task',
                'severity' => $event->severity?->value ?? 'medium',
                'status' => 'routed',
                'note' => "由 {$pack->domain} 交辦：{$h['task']}",
                'payload' => [
                    'a2a' => true,
                    'from_domain' => $pack->domain,
                    'parent_event_id' => $event->id,
                    'task' => $h['task'],
                    'artifact' => $h['artifact'],
                ],
            ]);

            RunCoordinatorJob::dispatch($child->id, $h['to']);
            $ctx->addFinding("🔀 已交辦「{$h['to']}」：{$h['task']}（A2A 事件 #{$child->id}）");
        }
    }

    /**
     * 把本次運行的總結與發現寫入領域記憶（L2）。以 run_id 去重，重放/續跑不重複。
     */
    private function rememberRun(AgentRun $run, PaiEvent $event, DomainPack $pack, AgentContext $ctx): void
    {
        $ns = $pack->memoryNamespace;
        $already = \App\Pai\Memory\Memory::query()
            ->where('namespace', $ns)
            ->where('metadata->run_id', $run->id)
            ->exists();
        if ($already) {
            return;
        }

        $content = trim(($ctx->summary ?? '')."\n".implode("\n", $ctx->findings));
        if ($content === '') {
            return;
        }

        $kind = match ($pack->domain) {
            'sec-ir' => 'incident',
            'dev-auto' => 'dev-task',
            default => 'note',
        };

        $this->memory->remember($ns, $content, $kind, [
            'run_id' => $run->id,
            'event_id' => $event->id,
            'topic' => $event->topic,
        ]);
    }

    /**
     * 依 autonomy / risk_policy 決定每個動作的最終狀態。
     *
     * @return array{0: list<array<string,mixed>>, 1: bool}  [動作清單, 是否需要人類核准]
     */
    private function gateActions(AgentContext $ctx, DomainPack $pack): array
    {
        $autonomy = $this->settings->domainAutonomy($pack->domain, $pack->autonomy);
        $needHitl = false;
        $actions = [];
        foreach ($ctx->actions as $a) {
            $requires = $this->requiresApproval($autonomy, $a['action'], $a['risk'], $pack);
            if ($requires) {
                $needHitl = true;
                $actions[] = [...$a, 'status' => 'awaiting_approval'];
            } else {
                // 低風險自動放行 → 真實執行
                $res = $this->executor->execute($a, $pack->domain);
                $actions[] = [...$a, 'status' => 'executed', 'result' => $res['output']];
            }
        }

        return [$actions, $needHitl];
    }

    private function requiresApproval(string $autonomy, string $action, string $risk, DomainPack $pack): bool
    {
        if ($pack->isHitlAction($action)) {
            return true;
        }

        return match ($autonomy) {
            'copilot' => true,                  // 一律需人類核准
            'supervisor' => $risk === 'high',   // 僅高風險
            'autopilot' => false,               // 邊界內全自主（hitl_required 已於上方擋下）
            default => true,
        };
    }

    /** @return array<string, mixed> 反思步驟 */
    private function reflect(AgentContext $ctx, int &$tokens): array
    {
        $prompt = "請以資深審查者角度，對以下處理做一句話自我批判：是否有遺漏或過度？\n"
            ."發現：".json_encode($ctx->findings, JSON_UNESCAPED_UNICODE)."\n"
            ."動作：".json_encode(array_column($ctx->actions, 'action'), JSON_UNESCAPED_UNICODE)."\n"
            ."只回一句話。";

        try {
            $res = $this->llm->complete([
                ['role' => 'user', 'content' => $prompt],
            ], ['max_tokens' => 2048]);
            $tokens += (int) ($res['usage']['total_tokens'] ?? 0);
            $critique = trim($res['content']);
        } catch (Throwable $e) {
            $critique = '（反思略過：'.$e->getMessage().'）';
        }

        return [
            'step' => 'reflect',
            'thought' => '自我批判 (Reflection)',
            'reasoning' => '',
            'action' => 'reflect',
            'action_input' => [],
            'observation' => $critique,
        ];
    }

    /** @param  array<string, Tool>  $tools */
    private function systemPrompt(DomainPack $pack, array $tools, int $maxSteps): string
    {
        $roster = implode("\n", array_map(
            static fn (array $a) => "  - {$a['name']}: {$a['role']}",
            $pack->roster,
        ));
        $toolDocs = implode("\n", array_map(
            static fn (Tool $t) => "  - {$t->name()}: {$t->description()}",
            array_values($tools),
        ));
        $capabilities = implode(', ', array_map(static fn ($t) => $t['uri'], $pack->tools));
        $hitl = $pack->hitlRequired === [] ? '（無）' : implode(', ', $pack->hitlRequired);

        return <<<PROMPT
        你是主動式 AI 平台的領域協調者「{$pack->coordinator}」，負責「{$pack->domain}」領域：{$pack->description}
        你的子智能體：
        {$roster}

        本領域可用工具/能力：{$capabilities}
        高風險（需人類核准）的動作：{$hitl}
        目前自治階段：{$pack->autonomy}

        你採用 ReAct 模式。每一步「只」輸出一個 JSON 物件，禁止任何其他文字。格式：
        {"thought": "你的推理", "action": "工具名", "action_input": { ... }}

        可用工具：
        {$toolDocs}

        流程建議：get_event_context 了解事件 →（必要時 recall_memory 查歷史）→ record_finding 記錄關鍵發現 → propose_action 提出處置 → finish 總結。
        最多 {$maxSteps} 步。提出處置時，action 盡量使用上述領域能力/動作鍵。
        PROMPT;
    }

    private function taskPrompt(PaiEvent $event): string
    {
        return sprintf(
            "一個事件進來了：topic=%s, intent=%s, severity=%s。請以你的職責開始處理，輸出第一步 JSON。",
            $event->topic,
            $event->intent ?? '未知',
            $event->severity?->value ?? '未知',
        );
    }
}
