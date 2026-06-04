<?php

namespace App\Pai\Cognition;

/** 工具執行結果。observation 會被回灌給 LLM 作為下一步觀察。 */
final readonly class ToolResult
{
    public function __construct(
        public bool $ok,
        public string $observation,
        public array $data = [],
    ) {}

    public static function ok(string $observation, array $data = []): self
    {
        return new self(true, $observation, $data);
    }

    public static function fail(string $observation): self
    {
        return new self(false, $observation);
    }
}
