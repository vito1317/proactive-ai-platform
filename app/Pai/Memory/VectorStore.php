<?php

namespace App\Pai\Memory;

/**
 * 向量儲存契約。database 驅動用 PHP 餘弦；production 換 pgvector。
 */
interface VectorStore
{
    /**
     * @param  list<float>  $embedding
     */
    public function add(string $namespace, string $content, array $embedding, string $kind = 'note', array $metadata = []): void;

    /**
     * 在命名空間內找最相似的 k 筆。
     *
     * @param  list<float>  $queryEmbedding
     * @return list<array{content: string, kind: string, score: float, metadata: array}>
     */
    public function search(string $namespace, array $queryEmbedding, int $k = 3): array;
}
