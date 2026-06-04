<?php

namespace App\Pai\Perception;

/**
 * 事件嚴重性。供 L3 主動性評估器判斷介入急迫度。
 */
enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /** 數值化，便於比較 / 排序。 */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }
}
