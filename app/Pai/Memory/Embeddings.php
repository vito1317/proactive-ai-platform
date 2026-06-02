<?php

namespace App\Pai\Memory;

/**
 * 文字 → 向量嵌入。可換驅動（本機 feature-hashing / OpenAI 相容）。
 */
interface Embeddings
{
    /** @return list<float> 已 L2 正規化的向量 */
    public function embed(string $text): array;

    public function dim(): int;
}
