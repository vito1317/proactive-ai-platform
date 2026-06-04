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

    /**
     * 任務跑完 → 只回覆「使用者主動發起」的任務（chat / 中控台 / TG / LINE）。
     * cron / 系統自動觸發的例行任務不打擾使用者（中控台軌跡仍可查）。
     */
    private function reportResult(PaiEvent $event, AgentRun $run): void
    {
        $convId = $event->payload['conversation_id'] ?? null;
        $conv = $convId ? Conversation::find($convId) : null;

        // 使用者主動發起 = 有來源對話，或來自 chat/console。否則（cron/log/webhook 等）不通知。
        if ($conv === null && ! in_array($event->source, ['chat', 'console'], true)) {
            return;
        }

        $domain = $run->domain;
        $summary = trim((string) ($run->summary ?? '')) ?: '（無總結）';
        $findings = is_array($run->findings) ? count($run->findings) : 0;
        $actions = is_array($run->actions) ? count($run->actions) : 0;

        $msg = match ($run->status) {
            RunStatus::Completed => "✅ 完成（{$domain}）\n{$summary}\n— 發現 {$findings} 項、動作 {$actions} 項",
            RunStatus::AwaitingHitl => "⏸️ 需要你核准（{$domain}）\n{$summary}\n到中控台「AI 認知運行」核准/駁回待執行動作。",
            RunStatus::Failed => "❌ 任務失敗（{$domain}）：".mb_substr((string) $run->error, 0, 200),
            RunStatus::Cancelled => null, // 使用者自己中止的，不用再通知
            default => null,
        };
        if ($msg === null) {
            return;
        }

        // 回貼到來源對話
        if ($conv) {
            $conv->addMessage('assistant', $msg, ['category' => 'task_result', 'event_id' => $event->id, 'domain' => $domain]);
            // 來源是 TG / LINE → 推回該頻道（聊天室訊息不會自動送到 TG）
            if ($conv->tg_chat_id) {
                app(Notifier::class)->sendTelegramTo((string) $conv->tg_chat_id, $msg);
            } elseif ($conv->line_to) {
                app(Notifier::class)->sendLineTo((string) $conv->line_to, $msg);
            }
        }

        // 網頁鈴鐺（僅使用者發起的任務；HITL 由 PushNotifier 處理，不重複）。不再廣播到所有外部通道。
        if ($run->status !== RunStatus::AwaitingHitl) {
            $users = User::all();
            if ($users->isNotEmpty()) {
                Notification::send($users, new PlatformNotice($msg, $run->status === RunStatus::Failed ? 'error' : 'info'));
            }
        }
    }
}
