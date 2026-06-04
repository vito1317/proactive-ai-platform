<?php

namespace App\Pai\Perception;

use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Domains\DomainRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * L1 → L3 交接：把一筆已落地的事件正規化 (intent/severity)，
 * 再依 DomainRegistry 路由到訂閱該主題的領域協調者。
 *
 * 跑在 queue 上（database driver），讓 webhook 端點能即時回 202。
 * 實際喚醒 L3 大腦的步驟會在認知層接上後於此 dispatch。
 */
class IngestEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $eventId) {}

    public function handle(EventNormalizer $normalizer, DomainRegistry $registry): void
    {
        $event = PaiEvent::find($this->eventId);
        if ($event === null) {
            return;
        }

        $norm = $normalizer->normalize($event->topic, $event->payload ?? []);
        $event->intent = $norm['intent'];
        $event->severity = $norm['severity'];
        $event->status = EventStatus::Normalized;
        $event->save();

        $domains = $registry->forEvent($event->topic);

        if ($domains === []) {
            $event->status = EventStatus::Ignored;
            $event->note = "無領域訂閱主題 {$event->topic}";
            $event->save();

            return;
        }

        // 一般情況一個主題對應一個領域；多個則全部記錄、主領域取第一個。
        $names = array_map(static fn ($p) => $p->domain, $domains);
        $event->domain = $names[0];
        $event->status = EventStatus::Routed;
        $event->note = '已路由至 '.implode(', ', array_map(
            static fn ($p) => $p->coordinator,
            $domains,
        ));
        $event->save();

        // L3：喚醒主領域協調者跑認知迴圈
        RunCoordinatorJob::dispatch($event->id, $event->domain);
    }

    public function failed(Throwable $e): void
    {
        Log::error('[PAI] IngestEventJob 失敗', ['event_id' => $this->eventId, 'error' => $e->getMessage()]);
        PaiEvent::where('id', $this->eventId)->update([
            'status' => EventStatus::Failed->value,
            'note' => '處理失敗：'.$e->getMessage(),
        ]);
    }
}
