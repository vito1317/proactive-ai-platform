<?php

namespace App\Pai\Call;

use Illuminate\Database\Eloquent\Model;

/**
 * 一通 AI 外撥電話任務（「幫我打去餐廳訂位」）。
 * PlaceCallSkill 建立 → OutboundCaller 經 Twilio 撥出 → webhook 回合制對話 → 收尾通知。
 *
 * @property int $id
 * @property int $user_id
 * @property string $to_number
 * @property string $goal
 * @property string $status pending|in_progress|completed|no_answer|busy|failed|canceled
 * @property array|null $transcript [{role: ai|callee, text}]
 * @property string|null $result
 * @property string|null $twilio_sid
 * @property string $token
 * @property int $turns
 */
class OutboundCall extends Model
{
    protected $fillable = [
        'user_id', 'to_number', 'goal', 'status', 'transcript', 'result', 'twilio_sid', 'token', 'turns',
    ];

    protected $casts = [
        'transcript' => 'array',
    ];

    public function appendTranscript(string $role, string $text): void
    {
        $t = (array) ($this->transcript ?? []);
        $t[] = ['role' => $role, 'text' => $text];
        $this->transcript = $t;
    }

    /** 逐字稿轉成可讀文字（通知 / LLM 總結用）。 */
    public function transcriptText(int $limit = 4000): string
    {
        $lines = collect((array) ($this->transcript ?? []))
            ->map(fn ($m) => (($m['role'] ?? '') === 'ai' ? '我方AI' : '對方').'：'.($m['text'] ?? ''))
            ->implode("\n");

        return mb_substr($lines, -$limit);
    }
}
