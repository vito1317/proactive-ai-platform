<?php

namespace App\Pai\Cognition\Tools;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;
use App\Pai\Memory\MemoryStore;

/**
 * 情境記憶語意檢索 (L2 / RAG)：以本次事件或關鍵字為查詢，
 * 從領域記憶命名空間找出最相似的過往處置，讓 AI「從過去經驗學習」。
 */
final class RecallMemoryTool implements Tool
{
    public function __construct(private readonly MemoryStore $memory) {}

    public function name(): string
    {
        return 'recall_memory';
    }

    public function description(): string
    {
        return '語意檢索此領域過去的相似事件與處置。可選 action_input: {"query":"..."}，預設用本次事件內容。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            $query = $ctx->event->topic.' '.($ctx->event->intent ?? '').' '
                .json_encode($ctx->event->payload, JSON_UNESCAPED_UNICODE);
        }

        $hits = $this->memory->recall($ctx->pack->memoryNamespace, $query);
        if ($hits === []) {
            return ToolResult::ok('記憶庫中無相似的過往處置（可能是首見）。');
        }

        $lines = array_map(
            fn ($h) => sprintf('• [%s, 相似度 %.2f] %s', $h['kind'], $h['score'], mb_substr($h['content'], 0, 160)),
            $hits,
        );

        return ToolResult::ok("檢索到 ".count($hits)." 筆相似記憶：\n".implode("\n", $lines));
    }
}
