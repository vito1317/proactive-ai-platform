<?php

namespace App\Pai\Perception;

use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Domains\DomainRegistry;

/**
 * 時間觸發（L1）：依領域包的 triggers.cron，定時主動喚醒協調者，
 * 讓平台具備「時間觀念」的主動性（如每早彙整威脅情報），而非僅被事件驅動。
 */
class CronTrigger
{
    public function __construct(private readonly DomainRegistry $registry) {}

    /** 為某領域產生一筆排程事件並喚醒其協調者。 */
    public function fire(string $domain, string $note = ''): ?PaiEvent
    {
        if (! $this->registry->has($domain)) {
            return null;
        }

        $event = PaiEvent::create([
            'source' => 'cron',
            'topic' => 'cron.tick',
            'domain' => $domain,
            'intent' => 'scheduled-routine',
            'severity' => Severity::Low,
            'status' => EventStatus::Routed,
            'note' => $note !== '' ? "排程觸發：{$note}" : '排程觸發',
            'payload' => ['cron' => true, 'routine' => $note],
        ]);

        RunCoordinatorJob::dispatch($event->id, $domain);

        return $event;
    }

    /**
     * 解析領域包的 cron 條目 "「<cron 運算式>: <說明>」"。
     *
     * @return array{0: string, 1: string}  [運算式, 說明]
     */
    public static function parse(string $entry): array
    {
        $parts = explode(':', $entry, 2);
        $expr = trim($parts[0]);
        $desc = trim($parts[1] ?? '');

        return [$expr, $desc];
    }
}
