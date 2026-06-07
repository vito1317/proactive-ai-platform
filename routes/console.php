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

// 使用者定時任務：「明天早上8:30開導航到台中」→ 每分鐘檢查到期 → 丟給指揮大腦執行
Schedule::call(function () {
    foreach (\App\Pai\Schedule\ScheduledTask::due() as $task) {
        $task->fire();
    }
})->everyMinute()->name('pai:user-scheduled-tasks')->withoutOverlapping();

// 晨間主動簡報：每天 briefing.time（預設 08:00）推天氣+行程+未讀信
Schedule::call(function () {
    $settings = app(\App\Pai\Settings\Settings::class);
    if (! (bool) $settings->get('briefing.enabled', true)) {
        return;
    }
    $time = (string) ($settings->get('briefing.time') ?: '08:00');
    if (now('Asia/Taipei')->format('H:i') !== $time) {
        return;
    }
    $key = 'pai:briefing:'.now('Asia/Taipei')->format('Y-m-d');
    if (\Illuminate\Support\Facades\Cache::add($key, 1, 86400)) {
        \App\Pai\Schedule\BriefingJob::dispatch();
    }
})->everyMinute()->name('pai:morning-briefing')->withoutOverlapping();

// 主動提醒：行事曆事件快開始（lead 分鐘內）→ 自動提醒一次
Schedule::call(function () {
    $cal = app(\App\Pai\Integrations\Calendar::class);
    if (! $cal->configured()) {
        return;
    }
    $lead = (int) (app(\App\Pai\Settings\Settings::class)->get('reminder.lead_min') ?: 15);
    $now = now('Asia/Taipei');
    foreach ($cal->upcoming(2) as $e) {
        if ($e['all_day']) {
            continue;
        }
        $mins = $now->diffInMinutes($e['start'], false);
        if ($mins < 0 || $mins > $lead) {
            continue;
        }
        $key = 'pai:evt-remind:'.md5($e['summary'].$e['start']->toIso8601String());
        if (\Illuminate\Support\Facades\Cache::add($key, 1, 7200)) {
            $when = $e['start']->format('H:i');
            $loc = $e['location'] !== '' ? "（{$e['location']}）" : '';
            app(\App\Pai\Notify\Notifier::class)->dispatch("⏰ 提醒：{$when} 有「{$e['summary']}」{$loc}，剩 {$mins} 分鐘。");
        }
    }
})->everyMinute()->name('pai:calendar-reminders')->withoutOverlapping();

// #8 自我修復：每 2 分鐘檢查關鍵服務，掛掉→嘗試重啟+通知
Schedule::call(fn () => \App\Pai\Perception\SelfHealJob::dispatch())
    ->everyTwoMinutes()->name('pai:self-heal')->withoutOverlapping();
