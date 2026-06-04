<?php

namespace App\Pai\Memory;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI 相容 /v1/embeddings 嵌入（production；需嵌入模型，如 llama-server 加 --embeddings）。
 */
class OpenAiEmbeddings implements Embeddings
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly int $dim,
    ) {}

    public function dim(): int
    {
        return $this->dim;
    }

    public function embed(string $text): array
    {
        $resp = Http::timeout(30)->withToken($this->apiKey)->acceptJson()
            ->post(rtrim($this->baseUrl, '/').'/embeddings', [
                'model' => $this->model,
                'input' => $text,
            ]);

        if ($resp->failed()) {
            throw new RuntimeException('嵌入請求失敗：'.$resp->status().' '.$resp->body());
        }

        $vec = $resp->json('data.0.embedding');
        if (! is_array($vec) || $vec === []) {
            throw new RuntimeException('嵌入回應格式錯誤。');
        }

        $vec = array_map('floatval', $vec);
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vec)));

        return $norm > 0 ? array_map(fn ($x) => $x / $norm, $vec) : $vec;
    }
}
