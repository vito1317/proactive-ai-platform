<?php

namespace App\Pai\Cognition;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * #9 LLM 用量觀測：每日彙總 calls / tokens / 平均延遲。
 *
 * @property string $day
 * @property int $calls
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $latency_ms
 */
class LlmUsage extends Model
{
    protected $fillable = ['day', 'calls', 'prompt_tokens', 'completion_tokens', 'latency_ms'];

    public static function record(int $prompt, int $completion, int $latencyMs): void
    {
        $day = now('Asia/Taipei')->toDateString();
        try {
            // upsert + 累加（避免併發覆蓋）
            static::firstOrCreate(['day' => $day]);
            static::where('day', $day)->update([
                'calls' => DB::raw('calls + 1'),
                'prompt_tokens' => DB::raw('prompt_tokens + '.$prompt),
                'completion_tokens' => DB::raw('completion_tokens + '.$completion),
                'latency_ms' => DB::raw('latency_ms + '.$latencyMs),
            ]);
        } catch (Throwable) {
        }
    }

    /** 中控台用：今日 + 近 7 日彙總。 */
    public static function summary(): array
    {
        $today = static::where('day', now('Asia/Taipei')->toDateString())->first();
        $week = static::where('day', '>=', now('Asia/Taipei')->subDays(6)->toDateString())->get();

        return [
            'today_calls' => (int) ($today->calls ?? 0),
            'today_tokens' => (int) (($today->prompt_tokens ?? 0) + ($today->completion_tokens ?? 0)),
            'today_avg_ms' => ($today && $today->calls > 0) ? (int) round($today->latency_ms / $today->calls) : 0,
            'week_calls' => (int) $week->sum('calls'),
            'week_tokens' => (int) ($week->sum('prompt_tokens') + $week->sum('completion_tokens')),
        ];
    }
}
