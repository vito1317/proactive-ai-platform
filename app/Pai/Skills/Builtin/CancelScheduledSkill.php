<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Schedule\ScheduledTask;
use App\Pai\Skills\Skill;

/** 消除待辦 / 定時任務（提醒、排程）。可指定關鍵字只清符合的，或不指定清全部 pending。 */
class CancelScheduledSkill implements Skill
{
    public function name(): string
    {
        return 'cancel-scheduled';
    }

    public function description(): string
    {
        return '消除/取消待辦或定時任務（提醒、排程）。query=關鍵字（只清符合的）；留空＝清掉全部待執行的。';
    }

    public function parameters(): array
    {
        return [
            'query' => '要消除的待辦關鍵字（選填；留空＝全部待執行的）',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $q = trim((string) ($args['query'] ?? ''));
        $query = ScheduledTask::where('status', 'pending');
        if ($q !== '') {
            $query->where('command', 'like', '%'.$q.'%');
        }
        $tasks = $query->orderBy('run_at')->get();
        if ($tasks->isEmpty()) {
            return $q !== '' ? "沒有符合「{$q}」的待辦/定時任務。" : '目前沒有待執行的待辦/定時任務。';
        }
        $n = $tasks->count();
        $names = $tasks->take(5)->map(fn ($t) => $t->command)->implode('、');
        ScheduledTask::whereIn('id', $tasks->pluck('id'))->update(['status' => 'cancelled']);

        return "🗑️ 已消除 {$n} 個待辦：{$names}".($n > 5 ? '…等' : '').'。';
    }
}
