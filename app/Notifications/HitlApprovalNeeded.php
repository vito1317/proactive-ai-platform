<?php

namespace App\Notifications;

use App\Pai\Cognition\AgentRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * 當協調者產出需人類核准的高風險動作時，推播給管理者（中控台鈴鐺）。
 */
class HitlApprovalNeeded extends Notification
{
    use Queueable;

    public function __construct(public readonly AgentRun $run) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $pending = collect($this->run->actions)
            ->where('status', 'awaiting_approval')
            ->pluck('action')
            ->all();

        return [
            'type' => 'hitl',
            'run_id' => $this->run->id,
            'domain' => $this->run->domain,
            'coordinator' => $this->run->coordinator,
            'message' => "需核准：{$this->run->coordinator} 提出 ".count($pending).' 個待核准動作',
            'actions' => $pending,
        ];
    }
}
