<?php

namespace App\Pai\Perception;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $path
 * @property int $offset
 */
class LogCursor extends Model
{
    protected $table = 'pai_log_cursors';

    protected $primaryKey = 'path';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['path', 'offset'];

    protected $casts = ['offset' => 'integer'];
}
