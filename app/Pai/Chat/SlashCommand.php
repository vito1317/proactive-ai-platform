<?php

namespace App\Pai\Chat;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property string $body
 * @property ?string $description
 * @property bool $enabled
 */
class SlashCommand extends Model
{
    protected $fillable = ['name', 'body', 'description', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
