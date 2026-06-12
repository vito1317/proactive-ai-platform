<?php

namespace App\Pai\Chat;

use App\Pai\Cognition\LlmClient;
use Throwable;

/**
 * 自動上下文壓縮：對話超過門檻時，把較舊的訊息用 LLM 摘要併入 summary，
 * 之後 prompt 只帶「摘要 + 近期訊息」，長對話不會無限膨脹也不丟失脈絡。
 * 原始訊息保留在 DB（後台仍可完整查看），只是不再進 prompt。
 */
class ContextCompactor
{
    /** 未壓縮訊息超過此數即觸發壓縮（預設值；實際讀 pai.chat.compact_threshold）。 */
    public const THRESHOLD = 24;

    /** 壓縮時保留最近 N 則不動（預設值；實際讀 pai.chat.keep_recent）。 */
    public const KEEP_RECENT = 8;

    public function __construct(private readonly LlmClient $llm) {}

    /** 觸發門檻（可由 PAI_CHAT_COMPACT_THRESHOLD 調整）。 */
    public static function threshold(): int
    {
        return max(2, (int) config('pai.chat.compact_threshold', self::THRESHOLD));
    }

    /** 保留最近幾則不壓縮（可由 PAI_CHAT_KEEP_RECENT 調整）。 */
    public static function keepRecent(): int
    {
        return max(1, (int) config('pai.chat.keep_recent', self::KEEP_RECENT));
    }

    public function shouldCompact(Conversation $conv): bool
    {
        return $conv->activeMessages()->count() > self::threshold();
    }

    /** 執行一次壓縮（冪等：失敗不影響對話，下次再試）。 */
    public function compact(Conversation $conv): void
    {
        $active = $conv->activeMessages()->get();
        if ($active->count() <= self::keepRecent()) {
            return;
        }
        $old = $active->slice(0, $active->count() - self::keepRecent())->values();
        $transcript = $old->map(fn ($m) => "{$m->role}: {$m->content}")->implode("\n");
        $prev = $conv->summary ? "（先前摘要）\n{$conv->summary}\n\n" : '';

        try {
            $summary = trim($this->llm->chat([[
                'role' => 'user',
                'content' => \App\Pai\Cognition\Prompts::render('compact-conversation', ['prev' => $prev, 'transcript' => $transcript]),
            ]], ['max_tokens' => 2048, 'tier' => 'small']));
        } catch (Throwable $e) {
            // 壓縮失敗不影響對話，之後再試；但要留下紀錄，長期失敗才看得見
            \Illuminate\Support\Facades\Log::warning('對話壓縮失敗', [
                'conversation_id' => $conv->id, 'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($summary !== '') {
            $conv->update(['summary' => $summary, 'compacted_through_id' => $old->last()->id]);
        }
    }
}
