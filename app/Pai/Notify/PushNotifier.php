<?php

namespace App\Pai\Notify;

use App\Models\User;
use App\Notifications\HitlApprovalNeeded;
use App\Pai\Cognition\AgentRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * 推播協同：高風險動作待核准時，通知中控台使用者（database 鈴鐺），
 * 並選擇性推到外部 webhook（Slack/Discord 相容的 {text}）。
 */
class PushNotifier
{
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

        // 外部 webhook（選配）
        $url = config('pai.notify.webhook_url');
        if ($url) {
            $pending = collect($run->actions)->where('status', 'awaiting_approval')->pluck('action')->implode(', ');
            try {
                Http::timeout(5)->post($url, [
                    'text' => "🛡️ PAI 需人類核准｜{$run->coordinator}（事件 #{$run->event_id}）待核准動作：{$pending}",
                ]);
            } catch (Throwable) {
                // 推播失敗不影響主流程
            }
        }
    }
}
