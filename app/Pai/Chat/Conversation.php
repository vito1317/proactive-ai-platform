<?php

namespace App\Pai\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property ?int $user_id
 * @property ?string $title
 */
class Conversation extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    public function addMessage(string $role, string $content, array $meta = []): ConversationMessage
    {
        return $this->messages()->create(['role' => $role, 'content' => $content, 'meta' => $meta]);
    }
}
