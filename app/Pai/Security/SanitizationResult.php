<?php

namespace App\Pai\Security;

/** 工具描述清洗結果。 */
final readonly class SanitizationResult
{
    /**
     * @param  string[]  $flags  觸發的可疑類別
     */
    public function __construct(
        public string $clean,
        public array $flags,
    ) {}

    public function isSuspicious(): bool
    {
        return $this->flags !== [];
    }
}
