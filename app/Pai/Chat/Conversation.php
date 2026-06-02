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
    protected $fillable = ['user_id', 'tg_chat_id', 'line_to', 'title'];

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    /** 取得（或建立）某 Telegram chat 的會話，以維持該對話上下文。 */
    public static function forTelegram(string $chatId): self
    {
        return static::firstOrCreate(['tg_chat_id' => $chatId], ['title' => "Telegram {$chatId}"]);
    }

    /** 取得（或建立）某 LINE 對象（userId/groupId/roomId）的會話。 */
    public static function forLine(string $to): self
    {
        return static::firstOrCreate(['line_to' => $to], ['title' => "LINE {$to}"]);
    }

    public function addMessage(string $role, string $content, array $meta = []): ConversationMessage
    {
        return $this->messages()->create(['role' => $role, 'content' => $content, 'meta' => $meta]);
    }
}
