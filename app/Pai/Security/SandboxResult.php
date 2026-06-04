<?php

namespace App\Pai\Security;

/** 沙盒執行結果。 */
final readonly class SandboxResult
{
    public function __construct(
        public bool $ok,
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $timedOut,
        public string $isolation,   // bwrap | process（隔離強度）
    ) {}
}
