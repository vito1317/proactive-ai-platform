<?php

namespace App\Pai\Memory;

/**
 * L2 記憶的高階門面：嵌入 + 向量儲存。協調者結束時把發現/總結寫入領域記憶，
 * 未來事件可語意檢索（RAG），讓 AI「從過去經驗學習」。
 */
class MemoryStore
{
    public function __construct(
        private readonly VectorStore $store,
        private readonly Embeddings $embeddings,
    ) {}

    public function remember(string $namespace, string $content, string $kind = 'note', array $metadata = []): void
    {
        $content = trim($content);
        if ($content === '') {
            return;
        }
        $this->store->add($namespace, $content, $this->embeddings->embed($content), $kind, $metadata);
    }

    /**
     * @return list<array{content: string, kind: string, score: float, metadata: array}>
     */
    public function recall(string $namespace, string $query, ?int $k = null): array
    {
        $k ??= (int) config('pai.memory.top_k', 3);

        return $this->store->search($namespace, $this->embeddings->embed($query), $k);
    }
}
