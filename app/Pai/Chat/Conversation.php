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

    /** 取得某 Telegram chat 目前作用中的會話（最新一個；/new 後就是新 session）。 */
    public static function forTelegram(string $chatId): self
    {
        return static::where('tg_chat_id', $chatId)->latest('id')->first()
            ?? static::create(['tg_chat_id' => $chatId]);
    }

    /** 取得某 LINE 對象（userId/groupId/roomId）目前作用中的會話。 */
    public static function forLine(string $to): self
    {
        return static::where('line_to', $to)->latest('id')->first()
            ?? static::create(['line_to' => $to]);
    }

    /** /new 指令：為該頻道開一個全新 session（舊上下文保留，後台仍可查看）。 */
    public static function newSession(string $platform, string $id): self
    {
        return static::create($platform === 'tg' ? ['tg_chat_id' => $id] : ['line_to' => $id]);
    }

    public function addMessage(string $role, string $content, array $meta = []): ConversationMessage
    {
        return $this->messages()->create(['role' => $role, 'content' => $content, 'meta' => $meta]);
    }
}
