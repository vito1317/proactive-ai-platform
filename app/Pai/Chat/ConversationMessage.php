<?php

namespace App\Pai\Chat;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property array $meta
 */
class ConversationMessage extends Model
{
    protected $fillable = ['conversation_id', 'role', 'content', 'meta'];

    protected $casts = ['meta' => 'array'];
}
