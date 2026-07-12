<?php

namespace App\Pai\Meeting;

use Illuminate\Database\Eloquent\Model;

/**
 * 一場會議記錄：手機分段錄音上傳 → transcript 累積 → 結束後 LLM 摘要。
 *
 * @property int $id
 * @property int $user_id
 * @property string $status recording|summarizing|done|error
 * @property string|null $transcript
 * @property string|null $summary
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 */
class Meeting extends Model
{
    protected $fillable = ['user_id', 'status', 'transcript', 'summary', 'started_at', 'ended_at'];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];

    public static function activeFor(int $uid): ?self
    {
        return self::where('user_id', $uid)->where('status', 'recording')->latest('id')->first();
    }

    public function appendTranscript(string $text): void
    {
        $stamp = now('Asia/Taipei')->format('H:i');
        $this->transcript = trim((string) $this->transcript."\n[{$stamp}] ".trim($text));
        $this->save();
    }
}
