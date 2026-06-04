<?php

namespace App\Pai\Mcp;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property ?array $headers
 * @property bool $enabled
 * @property ?array $tools
 * @property ?string $last_error
 */
class McpServer extends Model
{
    protected $fillable = ['name', 'url', 'headers', 'enabled', 'tools', 'last_error'];

    protected $casts = [
        'headers' => 'array',
        'tools' => 'array',
        'enabled' => 'boolean',
    ];
}
