<?php

namespace App\Pai\Domains;

use RuntimeException;

/**
 * 當一個領域包 manifest 不符合 docs/SPEC.md 契約時拋出。
 * 採「預設不信任」：驗證不過就拒載，不讓壞掉的領域污染平台。
 */
final class DomainPackValidationException extends RuntimeException
{
    /**
     * @param  string[]  $errors
     */
    public function __construct(
        public readonly string $source,
        public readonly array $errors,
    ) {
        parent::__construct(sprintf(
            "領域包 [%s] 驗證失敗：\n - %s",
            $source,
            implode("\n - ", $errors),
        ));
    }
}
