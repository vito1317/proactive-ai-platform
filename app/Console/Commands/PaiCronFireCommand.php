<?php

namespace App\Console\Commands;

use App\Pai\Perception\CronTrigger;
use Illuminate\Console\Command;

/**
 * 手動觸發某領域的排程例行（測試/示範用；正式由 Scheduler 依 cron 自動跑）。
 * 用法：php artisan pai:cron-fire sec-ir "每日威脅情報彙整"
 */
class PaiCronFireCommand extends Command
{
    protected $signature = 'pai:cron-fire {domain} {note=手動觸發}';

    protected $description = '手動觸發某領域的主動排程例行';

    public function handle(CronTrigger $trigger): int
    {
        $event = $trigger->fire($this->argument('domain'), (string) $this->argument('note'));
        if ($event === null) {
            $this->error('未知領域：'.$this->argument('domain'));

            return self::FAILURE;
        }

        $this->info("已主動觸發事件 #{$event->id}（{$this->argument('domain')}），協調者將於 queue 處理。");

        return self::SUCCESS;
    }
}
