<?php

namespace App\Pai\Memory;

use Illuminate\Support\Facades\Log;
use Throwable;

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

        // 去重：同 namespace 已有一字不差的內容就不重複寫（避免重複 finding 吃掉 recall 名額）
        if (Memory::where('namespace', $namespace)->where('content', $content)->exists()) {
            return;
        }

        try {
            $embedding = $this->embeddings->embed($content);
        } catch (Throwable $e) {
            // 嵌入服務掛掉不擋主流程：這筆記憶寫不進去，留紀錄
            Log::warning('記憶寫入失敗（嵌入服務不可用）', ['namespace' => $namespace, 'error' => $e->getMessage()]);

            return;
        }

        $this->store->add($namespace, $content, $embedding, $kind, $metadata);
    }

    /**
     * 語意檢索：回傳相似度達門檻（pai.memory.min_score）的前 k 筆。
     * 嵌入服務不可用時回空陣列（優雅降級，不擋主流程）。
     *
     * @return list<array{content: string, kind: string, score: float, metadata: array}>
     */
    public function recall(string $namespace, string $query, ?int $k = null): array
    {
        $k ??= (int) config('pai.memory.top_k', 3);

        try {
            $queryEmbedding = $this->embeddings->embed($query);
        } catch (Throwable $e) {
            Log::warning('記憶檢索失敗（嵌入服務不可用）', ['namespace' => $namespace, 'error' => $e->getMessage()]);

            return [];
        }

        $hits = $this->store->search($namespace, $queryEmbedding, $k);

        // 相似度門檻：低於門檻的「硬湊」結果不要注入 context 污染決策
        $min = (float) config('pai.memory.min_score', 0.15);

        return array_values(array_filter($hits, fn ($h) => ($h['score'] ?? 0.0) >= $min));
    }
}
