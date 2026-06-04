<?php

namespace App\Console\Commands;

use App\Pai\Perception\LogScanner;
use Illuminate\Console\Command;

/**
 * 手動掃描受監控日誌（正式由 Scheduler 每分鐘自動跑）。
 * 用法：php artisan pai:scan-logs
 */
class PaiScanLogsCommand extends Command
{
    protected $signature = 'pai:scan-logs';

    protected $description = '掃描受監控日誌，偵測新錯誤並觸發自動修復';

    public function handle(LogScanner $scanner): int
    {
        $n = $scanner->scan();
        $this->info("偵測到 {$n} 筆新錯誤，已發出 log.error 事件（log-ops 協調者將於 queue 處理）。");

        return self::SUCCESS;
    }
}
