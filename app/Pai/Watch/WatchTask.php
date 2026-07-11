<?php

namespace App\Pai\Watch;

use App\Pai\Mcp\McpServer;
use App\Pai\Mcp\ReverseBus;
use Illuminate\Database\Eloquent\Model;

/**
 * 一個視覺守望任務：「幫我盯著這個畫面，X 發生就叫我」。
 * WatchTickJob 週期性截圖判讀；命中/到期/取消後結束。
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $node
 * @property string $goal
 * @property int $interval_sec
 * @property \Illuminate\Support\Carbon $expires_at
 * @property string $status active|hit|expired|cancelled|error
 * @property string|null $last_desc
 * @property string|null $last_hash
 * @property string|null $tick_token
 * @property int $fail_count
 * @property int $run_count
 * @property \Illuminate\Support\Carbon|null $last_run_at
 * @property string|null $result
 */
class WatchTask extends Model
{
    protected $fillable = [
        'user_id', 'node', 'goal', 'interval_sec', 'expires_at', 'status',
        'last_desc', 'last_hash', 'tick_token', 'fail_count', 'run_count', 'last_run_at', 'result',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** 換發新的 tick 權杖（舊 Job 鏈看到權杖不符就自行退出，確保同時只有一條鏈在跑）。 */
    public function issueTickToken(): string
    {
        $this->tick_token = (string) \Illuminate\Support\Str::uuid();
        $this->save();

        return $this->tick_token;
    }

    /** 找該帳號「在線」的手機反向節點（優先非電腦類名稱），跟 AutomationEngine 同邏輯。 */
    public static function phoneNode(int $uid): ?string
    {
        try {
            $owned = McpServer::where('user_id', $uid)->where('url', 'like', 'reverse://%')->pluck('name')->all();
            $online = array_values(array_filter(ReverseBus::onlineNodes(), fn ($n) => in_array($n, $owned, true)));
            $phones = array_values(array_filter($online, fn ($n) => ! preg_match('/mac|macbook|imac|air|pc|desktop|windows|linux|laptop/i', $n)));

            return $phones[0] ?? $online[0] ?? null;
        } catch (\Throwable) {
        }

        return null;
    }
}
