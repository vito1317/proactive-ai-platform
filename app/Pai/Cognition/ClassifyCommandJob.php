<?php

namespace App\Pai\Cognition;

use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use App\Pai\Perception\Severity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 自然語言指令的處理：用 LLM 把白話對應到領域+主題，
 * 更新事件後路由並喚醒協調者。讓一般使用者免懂 topic/JSON。
 */
class ClassifyCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $eventId) {}

    public function handle(IntentClassifier $classifier): void
    {
        $event = PaiEvent::find($this->eventId);
        if ($event === null) {
            return;
        }

        $message = (string) ($event->payload['message'] ?? '');
        $result = $classifier->classify($message);

        if ($result['domain'] === null) {
            $event->status = EventStatus::Ignored;
            $event->intent = 'user-request';
            $event->note = '無法對應到任何領域：'.$result['rationale'];
            $event->save();

            return;
        }

        $event->topic = $result['topic'];
        $event->domain = $result['domain'];
        $event->intent = 'user-request';
        $event->severity = Severity::from($result['severity']);
        $event->status = EventStatus::Routed;
        $event->note = '自然語言指令 → '.$result['rationale'];
        $event->save();

        RunCoordinatorJob::dispatch($event->id, $event->domain);
    }
}
