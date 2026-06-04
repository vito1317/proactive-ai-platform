<?php

namespace App\Pai\Perception;

use Illuminate\Database\Eloquent\Model;

/**
 * 一筆感知事件 (L1)。OODA 迴圈的 Observe 產物。
 *
 * @property int $id
 * @property string $source
 * @property string $topic
 * @property array $payload
 * @property ?string $intent
 * @property ?Severity $severity
 * @property ?string $domain
 * @property EventStatus $status
 * @property ?string $note
 */
class PaiEvent extends Model
{
    protected $table = 'pai_events';

    protected $fillable = [
        'source', 'topic', 'payload', 'intent', 'severity', 'domain', 'status', 'note',
    ];

    protected $casts = [
        'payload' => 'array',
        'severity' => Severity::class,
        'status' => EventStatus::class,
    ];
}
