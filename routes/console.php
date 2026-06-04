<?php

use App\Pai\Domains\DomainRegistry;
use App\Pai\Perception\CronTrigger;
use App\Pai\Perception\LogScanner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// L1 時間觸發：依各領域包的 triggers.cron 註冊主動排程
foreach (app(DomainRegistry::class)->all() as $pack) {
    foreach ($pack->cronTriggers() as $entry) {
        [$expr, $desc] = CronTrigger::parse($entry);
        if ($expr === '') {
            continue;
        }
        Schedule::call(fn () => app(CronTrigger::class)->fire($pack->domain, $desc))
            ->cron($expr)
            ->name("pai:cron:{$pack->domain}:".substr(md5($entry), 0, 8))
            ->withoutOverlapping();
    }
}

// log-ops L1：每分鐘掃描日誌偵測新錯誤 → 自動發 log.error 事件
Schedule::call(fn () => app(LogScanner::class)->scan())
    ->everyMinute()
    ->name('pai:log-scan')
    ->withoutOverlapping();
