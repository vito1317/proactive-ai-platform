<?php

namespace App\Pai\Memory;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $namespace
 * @property string $kind
 * @property string $content
 * @property array $embedding
 * @property ?array $metadata
 */
class Memory extends Model
{
    protected $table = 'pai_memories';

    protected $fillable = ['namespace', 'kind', 'content', 'embedding', 'metadata'];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];
}
