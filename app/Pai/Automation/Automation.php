<?php

namespace App\Pai\Automation;

use Illuminate\Database\Eloquent\Model;

/**
 * 一條自動化流程：trigger（何時跑）→ conditions（成立才繼續）→ actions（做什麼）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property bool $enabled
 * @property array $spec
 * @property array|null $state
 * @property string $source
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int|null $max_runs
 * @property int $run_count
 * @property \Illuminate\Support\Carbon|null $last_run_at
 */
class Automation extends Model
{
    protected $fillable = ['user_id', 'name', 'enabled', 'spec', 'state', 'source', 'expires_at', 'max_runs', 'run_count', 'last_run_at'];

    protected $casts = [
        'enabled' => 'boolean',
        'spec' => 'array',
        'state' => 'array',
        'expires_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    /** 是否已達自動停止條件（到期 / 跑滿次數）。 */
    public function isAutoStopped(): bool
    {
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return true;
        }
        if ($this->max_runs !== null && (int) $this->run_count >= (int) $this->max_runs) {
            return true;
        }

        return false;
    }

    /** 把使用者/AI 給的截止時間字串（'YYYY-MM-DD HH:MM' / 'null' / 相對詞）轉成 Carbon 或 null。 */
    public static function parseExpiry(mixed $raw): ?\Illuminate\Support\Carbon
    {
        if ($raw === null || $raw === '' || (is_string($raw) && in_array(mb_strtolower(trim($raw)), ['null', 'none', '無', '不限'], true))) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse((string) $raw, 'Asia/Taipei');
        } catch (\Throwable) {
            return null;
        }
    }

    /** 把次數上限轉成正整數或 null。 */
    public static function parseMaxRuns(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || (is_string($raw) && in_array(mb_strtolower(trim($raw)), ['null', 'none'], true))) {
            return null;
        }
        $n = (int) $raw;

        return $n > 0 ? $n : null;
    }

    /** 人類可讀的「自動停止」說明（給 UI 顯示）。 */
    public function autoStopLabel(): ?string
    {
        $parts = [];
        if ($this->expires_at !== null) {
            $parts[] = $this->expires_at->format('Y-m-d H:i').' 到期';
        }
        if ($this->max_runs !== null) {
            $parts[] = "已跑 {$this->run_count}/{$this->max_runs} 次";
        }

        return $parts ? implode('、', $parts) : null;
    }
}
