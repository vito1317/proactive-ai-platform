<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Artisan;

/** 重啟佇列 worker（讓改過的領域包 / 程式碼生效）。高風險。 */
class RestartWorkersSkill implements Skill
{
    public function name(): string
    {
        return 'restart-workers';
    }

    public function description(): string
    {
        return '優雅重啟佇列 worker，讓新增/停用的領域包與設定完全生效';
    }

    public function parameters(): array
    {
        return [];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        // queue:restart 送出重啟訊號，worker 處理完當前 job 後自動退出（systemd 會拉起新的）
        Artisan::call('queue:restart');

        return '已送出 worker 重啟訊號 🔄，進行中的工作完成後會以最新設定/領域重新啟動。';
    }
}
