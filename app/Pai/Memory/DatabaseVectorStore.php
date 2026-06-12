<?php

namespace App\Pai\Memory;

/**
 * 可攜的向量儲存：嵌入以 JSON 存於 pai_memories，相似度於 PHP 計算
 * （向量已 L2 正規化，餘弦 = 內積）。適用 SQLite / MySQL，單機/開發足矣。
 */
class DatabaseVectorStore implements VectorStore
{
    public function add(string $namespace, string $content, array $embedding, string $kind = 'note', array $metadata = []): void
    {
        Memory::create([
            'namespace' => $namespace,
            'kind' => $kind,
            'content' => $content,
            'embedding' => $embedding,
            'metadata' => $metadata,
        ]);
    }

    public function search(string $namespace, array $queryEmbedding, int $k = 3): array
    {
        // 分批掃整個 namespace（舊版只掃最近 500 筆 → 超過後較舊記憶永遠搜不到）。
        // 每批計分後只保留目前 top-k 候選，記憶體用量與資料量無關。
        $top = [];
        Memory::where('namespace', $namespace)
            ->select(['id', 'content', 'kind', 'embedding', 'metadata'])
            ->chunkById(500, function ($rows) use (&$top, $queryEmbedding, $k) {
                foreach ($rows as $row) {
                    $top[] = [
                        'content' => $row->content,
                        'kind' => $row->kind,
                        'score' => $this->dot($queryEmbedding, $row->embedding),
                        'metadata' => $row->metadata ?? [],
                    ];
                }
                if (count($top) > $k * 4) {
                    usort($top, fn ($a, $b) => $b['score'] <=> $a['score']);
                    $top = array_slice($top, 0, $k);
                }
            });

        usort($top, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($top, 0, $k);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function dot(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }
}
