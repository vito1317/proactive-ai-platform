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
    protected $fillable = ['user_id', 'tg_chat_id', 'line_to', 'voice_sid', 'title', 'summary', 'compacted_through_id', 'pending_skill', 'always_allow_skills'];

    protected $casts = ['pending_skill' => 'array', 'always_allow_skills' => 'boolean'];

    /** 尚未被壓縮進 summary 的訊息（進 prompt 用）。 */
    public function activeMessages(): HasMany
    {
        return $this->messages()->when($this->compacted_through_id,
            fn ($q) => $q->where('id', '>', $this->compacted_through_id));
    }

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
        $msg = $this->messages()->create(['role' => $role, 'content' => $content, 'meta' => $meta]);

        // 自動上下文壓縮：每次 AI 回覆後檢查，超過門檻就排背景摘要（不阻塞回覆）
        if ($role === 'assistant' && $this->activeMessages()->count() > ContextCompactor::THRESHOLD) {
            CompactConversationJob::dispatch($this->id);
        }

        return $msg;
    }
}
