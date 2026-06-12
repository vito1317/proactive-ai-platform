<?php

namespace App\Pai\Governance;

use App\Pai\Action\ActionExecutor;
use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\RunStatus;

/**
 * L5 人機協同核准的共用邏輯：核准 = 真實放行執行（經 ActionExecutor）；駁回 = 標記 rejected。
 * 核准/駁回都記進 ProactivityPolicy 回饋，常被駁回的動作之後會自動降級。
 * 中控台（ConsoleController）與裝置 API（HitlController）共用，避免兩套邏輯走鐘。
 */
class Hitl
{
    public function __construct(private readonly ActionExecutor $executor) {}

    /**
     * 核准 / 駁回 run 的待核准動作。$index=null → 處理「所有」awaiting_approval（手機一鍵接受/拒絕整則）。
     *
     * @return array{ok: bool, message: string, decided: int}
     */
    public function decide(AgentRun $run, string $decision, ?int $index = null): array
    {
        $actions = $run->actions;
        $indices = $index !== null
            ? [$index]
            : array_keys(array_filter($actions, fn ($a) => ($a['status'] ?? null) === 'awaiting_approval'));

        $done = [];
        foreach ($indices as $i) {
            if (! isset($actions[$i]) || ($actions[$i]['status'] ?? null) !== 'awaiting_approval') {
                continue;
            }
            if ($decision === 'approve') {
                $result = $this->executor->execute($actions[$i], $run->domain);
                $actions[$i]['status'] = 'executed';
                $actions[$i]['result'] = $result['output'];
            } else {
                $actions[$i]['status'] = 'rejected';
            }
            app(ProactivityPolicy::class)->recordFeedback($run->domain, (string) $actions[$i]['action'], $decision === 'approve');
            $done[] = (string) $actions[$i]['action'];
        }

        if (empty($done)) {
            return ['ok' => false, 'message' => '此動作已處理過了。', 'decided' => 0];
        }

        $run->actions = $actions;
        $stillPending = collect($actions)->contains(fn ($a) => ($a['status'] ?? null) === 'awaiting_approval');
        $run->status = $stillPending ? RunStatus::AwaitingHitl : RunStatus::Completed;
        $run->save();

        PaidProtocolRecord::write($run);

        // PAID 第 1 層：若來源事件來自 paigent 節點（payload 自報 feedback_url），
        // 把人類定論回送該節點的 ReflectiveMemory（非節點來源會安靜略過）。
        GuardianFeedback::fromRun($run, $decision === 'approve');

        $verb = $decision === 'approve' ? '已核准並執行' : '已駁回';

        return ['ok' => true, 'message' => '動作「'.implode('、', $done)."」{$verb}。", 'decided' => count($done)];
    }
}
