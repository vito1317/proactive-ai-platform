<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Skills\Skill;
use App\Pai\Watch\WatchTask;

/** 取消/查看視覺守望。query=關鍵字（只取消符合的）；留空＝取消全部進行中的守望。 */
class CancelWatchSkill implements Skill
{
    public function name(): string
    {
        return 'cancel-watch';
    }

    public function description(): string
    {
        return '取消守望模式（停止盯畫面）。query=守望目標關鍵字（只取消符合的）；留空＝取消全部進行中的守望。'
            .'若使用者只是想「查目前在盯什麼」，帶 list_only=true。';
    }

    public function parameters(): array
    {
        return [
            'query' => '（選填）要取消的守望關鍵字；留空＝全部',
            'list_only' => '（選填）true=只列出進行中的守望，不取消',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $uid = Tenant::id();
        if ($uid === null) {
            return '無法判斷帳號，請在登入情境下使用。';
        }
        $q = trim((string) ($args['query'] ?? ''));
        $query = WatchTask::where('user_id', $uid)->where('status', 'active');
        if ($q !== '') {
            $query->where('goal', 'like', '%'.$q.'%');
        }
        $watches = $query->orderBy('id')->get();
        if ($watches->isEmpty()) {
            return $q !== '' ? "沒有符合「{$q}」的進行中守望。" : '目前沒有在盯任何畫面。';
        }

        $lines = $watches->map(fn ($w) => "#{$w->id} {$w->goal}（每 {$w->interval_sec}s，已看 {$w->run_count} 次，"
            .$w->expires_at->timezone('Asia/Taipei')->format('H:i').' 到期）')->implode("\n");

        $listOnly = filter_var($args['list_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($listOnly) {
            return "👀 進行中的守望：\n{$lines}";
        }

        WatchTask::whereIn('id', $watches->pluck('id'))->update(['status' => 'cancelled']);

        return "🗑️ 已取消 {$watches->count()} 個守望：\n{$lines}";
    }
}
