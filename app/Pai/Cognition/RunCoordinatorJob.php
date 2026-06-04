<?php

namespace App\Pai\Cognition;

use App\Models\User;
use App\Notifications\PlatformNotice;
use App\Pai\Chat\Conversation;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Notify\Notifier;
use App\Pai\Perception\PaiEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * L1/L3 交接：對已路由的事件，喚醒對應領域協調者跑認知迴圈。
 * 跑在 queue 上——LLM 推理可能耗時數十秒，不可阻塞請求。
 */
class RunCoordinatorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;     // 26B 模型多步推理可能較久

    public int $tries = 2;         // 崩潰/逾時可重試——靠 resume() 從已存步驟續跑，不重複動作

    public function __construct(public int $eventId, public string $domain) {}

    public function handle(DomainRegistry $registry, CognitiveEngine $engine): void
    {
        $event = PaiEvent::find($this->eventId);
        $pack = $registry->get($this->domain);
        if ($event === null || $pack === null) {
            return;
        }

        // 若已有中斷（running）的運行 → 精確續跑，不重燒先前推論
        $interrupted = AgentRun::query()
            ->where('event_id', $this->eventId)
            ->where('status', RunStatus::Running->value)
            ->latest('id')
            ->first();

        $run = $interrupted !== null ? $engine->resume($interrupted) : $engine->run($event, $pack);

        $this->reportResult($event, $run);
    }

    /** 任務跑完 → 把結果回貼到來源對話（若有）+ 發鈴鐺/外部通知。 */
    private function reportResult(PaiEvent $event, AgentRun $run): void
    {
        $domain = $run->domain;
        $summary = trim((string) ($run->summary ?? '')) ?: '（無總結）';
        $findings = is_array($run->findings) ? count($run->findings) : 0;
        $actions = is_array($run->actions) ? count($run->actions) : 0;

        $msg = match ($run->status) {
            RunStatus::Completed => "✅ 任務完成（{$domain} · 事件 #{$event->id}）\n{$summary}\n— 發現 {$findings} 項、動作 {$actions} 項",
            RunStatus::AwaitingHitl => "⏸️ 任務需要你核准（{$domain} · 事件 #{$event->id}）\n{$summary}\n到中控台「AI 認知運行」核准/駁回待執行動作。",
            RunStatus::Failed => "❌ 任務失敗（{$domain} · 事件 #{$event->id}）：".mb_substr((string) $run->error, 0, 200),
            RunStatus::Cancelled => "🛑 任務已中止（{$domain} · 事件 #{$event->id}）。",
            default => null,
        };
        if ($msg === null) {
            return;
        }

        // 回貼到來源對話（chat/console 觸發時帶了 conversation_id）
        $convId = $event->payload['conversation_id'] ?? null;
        if ($convId && ($conv = Conversation::find($convId))) {
            $conv->addMessage('assistant', $msg, ['category' => 'task_result', 'event_id' => $event->id, 'domain' => $domain]);
        }

        // 鈴鐺 + 外部通知。AwaitingHitl 已由 PushNotifier::hitlNeeded 發過 → 不重複。
        if ($run->status !== RunStatus::AwaitingHitl) {
            $users = User::all();
            if ($users->isNotEmpty()) {
                Notification::send($users, new PlatformNotice($msg, $run->status === RunStatus::Failed ? 'error' : 'info'));
            }
            app(Notifier::class)->send($msg);
        }
    }
}
