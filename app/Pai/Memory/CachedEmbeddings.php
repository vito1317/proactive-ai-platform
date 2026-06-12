<?php

namespace App\Pai\Memory;

use Illuminate\Support\Facades\Cache;

/**
 * 嵌入快取 decorator：相同文字不重算（remember/recall 常重複嵌入同一段內容，
 * 對 OpenAI 相容後端可省一次 HTTP 來回）。鍵帶驅動類別與維度，換驅動不會吃到舊向量。
 */
class CachedEmbeddings implements Embeddings
{
    private const TTL_SECONDS = 7 * 86400;

    public function __construct(private readonly Embeddings $inner) {}

    public function dim(): int
    {
        return $this->inner->dim();
    }

    public function embed(string $text): array
    {
        $key = 'pai:emb:'.class_basename($this->inner).':'.$this->inner->dim().':'.sha1($text);

        return Cache::remember($key, self::TTL_SECONDS, fn () => $this->inner->embed($text));
    }
}
