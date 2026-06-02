<?php

namespace App\Pai\Memory;

/**
 * 離線、確定性的嵌入：把文字特徵（CJK 字 n-gram + ASCII 詞）以 feature hashing
 * 映射到固定維度並 L2 正規化。捕捉詞彙重疊——相似文字餘弦較高，足以示範 RAG 檢索，
 * 且無需嵌入模型、可重現於測試。production 可換 {@see OpenAiEmbeddings}。
 */
class LocalHashEmbeddings implements Embeddings
{
    public function __construct(private readonly int $dim = 256) {}

    public function dim(): int
    {
        return $this->dim;
    }

    public function embed(string $text): array
    {
        $vec = array_fill(0, $this->dim, 0.0);
        foreach ($this->features($text) as $feature) {
            $h = crc32($feature);
            $bucket = $h % $this->dim;
            $sign = (($h >> 1) & 1) === 1 ? 1.0 : -1.0; // 隨機正負號，降低碰撞偏差
            $vec[$bucket] += $sign;
        }

        // L2 正規化 → 餘弦相似度 = 內積
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vec)));
        if ($norm > 0) {
            foreach ($vec as $i => $v) {
                $vec[$i] = $v / $norm;
            }
        }

        return $vec;
    }

    /** @return list<string> */
    private function features(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $features = [];

        // ASCII 詞 token
        if (preg_match_all('/[a-z0-9_]+/u', $text, $m)) {
            foreach ($m[0] as $w) {
                $features[] = 'w:'.$w;
            }
        }

        // 字元 bi/tri-gram（對 CJK 特別有效，無空白分詞問題）
        $chars = preg_split('//u', preg_replace('/\s+/u', '', $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            if ($i + 1 < $n) {
                $features[] = 'b:'.$chars[$i].$chars[$i + 1];
            }
            if ($i + 2 < $n) {
                $features[] = 't:'.$chars[$i].$chars[$i + 1].$chars[$i + 2];
            }
        }

        return $features ?: ['__empty__'];
    }
}
