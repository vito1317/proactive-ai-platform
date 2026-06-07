<?php

namespace App\Pai\Schedule;

use App\Pai\Cognition\RouteCommandJob;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * 使用者定時任務：「明天早上8:30幫我開導航到台中」「每天早上8點報天氣」。
 * 排程器每分鐘掃到期任務 → 丟給指揮大腦（RouteCommandJob）實際執行，
 * 結果走既有通知管線（語音念回 / 手機通知 / TG / LINE）。
 *
 * @property int $id
 * @property string $command
 * @property \Illuminate\Support\Carbon $run_at
 * @property ?string $recur
 * @property ?int $conversation_id
 * @property string $status
 */
class ScheduledTask extends Model
{
    protected $fillable = ['command', 'run_at', 'recur', 'conversation_id', 'status', 'last_run_at'];

    protected $casts = ['run_at' => 'datetime', 'last_run_at' => 'datetime'];

    /** @return \Illuminate\Database\Eloquent\Collection<int, static> 所有到期待執行的任務 */
    public static function due()
    {
        return static::where('status', 'pending')->where('run_at', '<=', now())->get();
    }

    /** 觸發執行：丟給指揮大腦；每日任務排下一次、一次性標記完成。 */
    public function fire(): void
    {
        $event = PaiEvent::create([
            'source' => 'schedule', 'topic' => 'console.request',
            'payload' => ['message' => $this->command, 'conversation_id' => $this->conversation_id],
            'status' => EventStatus::Received,
        ]);
        RouteCommandJob::dispatch($event->id);

        if ($this->recur === 'daily') {
            // 用「原定時間+1天」而不是 now+1天，避免延遲漂移
            $next = $this->run_at->copy()->addDay();
            while ($next->isPast()) {
                $next = $next->addDay();
            }
            $this->update(['run_at' => $next, 'last_run_at' => now()]);
        } else {
            $this->update(['status' => 'done', 'last_run_at' => now()]);
        }
    }
}
