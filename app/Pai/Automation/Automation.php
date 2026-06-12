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
 */
class Automation extends Model
{
    protected $fillable = ['user_id', 'name', 'enabled', 'spec', 'state', 'source'];

    protected $casts = [
        'enabled' => 'boolean',
        'spec' => 'array',
        'state' => 'array',
    ];
}
