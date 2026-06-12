<?php

namespace App\Pai\Cognition;

/**
 * 粗略 token 估算（無 tokenizer 依賴）：CJK 每字約 1 token、其餘約 4 字元 1 token。
 * 用途是「上下文預算裁切」——寧可略為高估，避免實際超出模型 context window 被無聲截頭。
 */
class TokenEstimator
{
    /** 估算一段文字的 token 數。 */
    public static function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        // CJK（中日韓 + 全形標點）字數
        $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text);
        $cjk = $cjk === false ? 0 : $cjk;
        // 其餘字元（ASCII / 拉丁等）以 4 字元 ≈ 1 token 估
        $other = max(0, mb_strlen($text) - $cjk);

        return $cjk + (int) ceil($other / 4);
    }

    /**
     * 估算 chat messages 的 token 總數（每則加少量框架開銷；
     * 多模態 content parts 只估文字段，圖片另以固定成本估）。
     *
     * @param  list<array{role: string, content: string|array}>  $messages
     */
    public static function estimateMessages(array $messages): int
    {
        $sum = 0;
        foreach ($messages as $m) {
            $sum += 4; // 每則訊息的角色/分隔框架開銷
            $c = $m['content'] ?? '';
            if (is_string($c)) {
                $sum += self::estimate($c);

                continue;
            }
            foreach ((array) $c as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $sum += self::estimate((string) ($part['text'] ?? ''));
                } elseif (is_array($part) && ($part['type'] ?? '') === 'image_url') {
                    $sum += 1024; // 影像視模型而定，估固定成本
                }
            }
        }

        return $sum;
    }

    /** 把文字裁到大約 $maxTokens 以內（保留開頭；尾端加省略標記）。 */
    public static function truncate(string $text, int $maxTokens): string
    {
        if ($maxTokens <= 0) {
            return '';
        }
        if (self::estimate($text) <= $maxTokens) {
            return $text;
        }
        // 二分逼近：找出不超過預算的最大字元數（估算單調遞增）
        $lo = 0;
        $hi = mb_strlen($text);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if (self::estimate(mb_substr($text, 0, $mid)) <= $maxTokens) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return mb_substr($text, 0, $lo).'…（已截斷）';
    }
}
