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
    /** 未壓縮訊息超過此數即觸發壓縮。 */
    public const THRESHOLD = 24;

    /** 壓縮時保留最近 N 則不動（維持語氣與即時脈絡）。 */
    public const KEEP_RECENT = 8;

    public function __construct(private readonly LlmClient $llm) {}

    public function shouldCompact(Conversation $conv): bool
    {
        return $conv->activeMessages()->count() > self::THRESHOLD;
    }

    /** 執行一次壓縮（冪等：失敗不影響對話，下次再試）。 */
    public function compact(Conversation $conv): void
    {
        $active = $conv->activeMessages()->get();
        if ($active->count() <= self::KEEP_RECENT) {
            return;
        }
        $old = $active->slice(0, $active->count() - self::KEEP_RECENT)->values();
        $transcript = $old->map(fn ($m) => "{$m->role}: {$m->content}")->implode("\n");
        $prev = $conv->summary ? "（先前摘要）\n{$conv->summary}\n\n" : '';

        try {
            $summary = trim($this->llm->chat([[
                'role' => 'user',
                'content' => "把以下對話濃縮成一段精簡摘要（繁體中文、條列重點：使用者的目標/偏好、已完成的事、未完成的承諾、關鍵參數）。只輸出摘要本身。\n\n{$prev}{$transcript}",
            ]], ['max_tokens' => 2048]));
        } catch (Throwable) {
            return; // 壓縮失敗不影響對話，之後再試
        }

        if ($summary !== '') {
            $conv->update(['summary' => $summary, 'compacted_through_id' => $old->last()->id]);
        }
    }
}
