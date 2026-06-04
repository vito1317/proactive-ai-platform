<?php

namespace App\Pai\Notify;

use App\Models\User;
use App\Notifications\HitlApprovalNeeded;
use App\Pai\Cognition\AgentRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * 推播協同：高風險動作待核准時，通知中控台使用者（database 鈴鐺），
 * 並透過 {@see Notifier} 推到所有已設定的外部平台（Telegram / LINE / webhook）。
 */
class PushNotifier
{
    public function __construct(private readonly Notifier $notifier) {}

    public function hitlNeeded(AgentRun $run): void
    {
        // 去重：同一運行只推一次（resume/重放不重複）
        if (DB::table('notifications')->where('data->run_id', $run->id)->exists()) {
            return;
        }

        // 中控台鈴鐺（database channel）
        $users = User::all();
        if ($users->isNotEmpty()) {
            Notification::send($users, new HitlApprovalNeeded($run));
        }

        // 外部平台（Telegram / LINE / webhook，皆選配）
        $pending = collect($run->actions)->where('status', 'awaiting_approval')->pluck('action')->implode(', ');
        $this->notifier->send("🛡️ PAI 需人類核准｜{$run->coordinator}（事件 #{$run->event_id}）待核准動作：{$pending}");
    }
}
