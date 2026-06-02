<?php

namespace App\Pai\Cognition;

use App\Pai\Domains\DomainRegistry;
use App\Pai\Perception\PaiEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        if ($interrupted !== null) {
            $engine->resume($interrupted);

            return;
        }

        $engine->run($event, $pack);
    }
}
