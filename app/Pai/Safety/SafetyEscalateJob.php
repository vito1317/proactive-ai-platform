<?php

namespace App\Pai\Safety;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** 安全確認倒數：到點還沒解除（沒回「我沒事」）→ 自動求援。 */
class SafetyEscalateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(private readonly int $uid) {}

    public function handle(SafetyGuard $guard): void
    {
        $guard->escalateIfStillPending($this->uid);
    }
}
