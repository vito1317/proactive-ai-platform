<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\RunStatus;
use App\Pai\Skills\Skill;

/** 中止進行中的任務（AgentRun）。高風險。 */
class StopTaskSkill implements Skill
{
    public function name(): string
    {
        return 'stop-task';
    }

    public function description(): string
    {
        return '中止進行中的 AI 任務/認知運行（指定運行編號，或不指定則中止最近一個進行中的）';
    }

    public function parameters(): array
    {
        return [
            'run_id' => '要中止的運行編號（選填；省略則中止最近進行中的任務）',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $query = AgentRun::whereIn('status', [RunStatus::Running, RunStatus::AwaitingHitl]);
        if (! empty($args['run_id'])) {
            $query->whereKey($args['run_id']);
        }
        $run = $query->latest('id')->first();

        if (! $run) {
            return '目前沒有可中止的進行中任務。';
        }
        $run->update(['status' => RunStatus::Cancelled]);

        return "已中止任務 #{$run->id}（領域：{$run->domain}）🛑。協調者下一步會停止，不再產生新動作。";
    }
}
