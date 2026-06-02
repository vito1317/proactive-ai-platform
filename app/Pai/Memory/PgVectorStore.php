<?php

namespace App\Pai\Memory;

use Illuminate\Support\Facades\DB;

/**
 * Production 向量儲存：PostgreSQL + pgvector。
 *
 * 啟用前置（prod）：
 *   CREATE EXTENSION IF NOT EXISTS vector;
 *   ALTER TABLE pai_memories ALTER COLUMN embedding TYPE vector(<dim>) USING embedding::vector;
 *   CREATE INDEX ON pai_memories USING hnsw (embedding vector_cosine_ops);
 *
 * 用 `<=>`（餘弦距離）做近鄰搜尋，交由資料庫索引加速（database 驅動則於 PHP 計算）。
 */
class PgVectorStore implements VectorStore
{
    public function add(string $namespace, string $content, array $embedding, string $kind = 'note', array $metadata = []): void
    {
        DB::insert(
            'INSERT INTO pai_memories (namespace, kind, content, embedding, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?::vector, ?, now(), now())',
            [$namespace, $kind, $content, $this->literal($embedding), json_encode($metadata, JSON_UNESCAPED_UNICODE)],
        );
    }

    public function search(string $namespace, array $queryEmbedding, int $k = 3): array
    {
        $rows = DB::select(
            'SELECT content, kind, metadata, 1 - (embedding <=> ?::vector) AS score
             FROM pai_memories WHERE namespace = ?
             ORDER BY embedding <=> ?::vector LIMIT ?',
            [$this->literal($queryEmbedding), $namespace, $this->literal($queryEmbedding), $k],
        );

        return array_map(fn ($r) => [
            'content' => $r->content,
            'kind' => $r->kind,
            'score' => (float) $r->score,
            'metadata' => json_decode($r->metadata ?? '[]', true) ?: [],
        ], $rows);
    }

    /** @param  list<float>  $vec */
    private function literal(array $vec): string
    {
        return '['.implode(',', array_map(fn ($x) => (string) $x, $vec)).']';
    }
}
