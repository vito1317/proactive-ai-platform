<?php

namespace App\Pai\Governance;

use Illuminate\Database\Eloquent\Model;

/**
 * 人類對動作的核准/駁回紀錄（HITL 回饋）。
 * ProactivityPolicy 據此自動降級「最近常被駁回」的動作（回饋調節）。
 *
 * @property int $id
 * @property string $domain
 * @property string $action
 * @property bool $positive
 */
class ActionFeedback extends Model
{
    protected $table = 'pai_action_feedback';

    protected $fillable = ['domain', 'action', 'positive'];

    protected $casts = ['positive' => 'boolean'];

    /** 視窗內「最近一次核准之後」的駁回次數（核准會重置計數，與 framework 的連續拒絕語意一致）。 */
    public static function recentDeclines(string $domain, string $action, int $windowDays): int
    {
        $since = now()->subDays(max(1, $windowDays));
        // 以 id 判斷先後（同秒寫入時 created_at 無法區分順序）
        $lastApproveId = static::where('domain', $domain)->where('action', $action)
            ->where('positive', true)->where('created_at', '>=', $since)->max('id');

        return static::where('domain', $domain)->where('action', $action)
            ->where('positive', false)
            ->where('created_at', '>=', $since)
            ->when($lastApproveId !== null, fn ($q) => $q->where('id', '>', $lastApproveId))
            ->count();
    }
}
