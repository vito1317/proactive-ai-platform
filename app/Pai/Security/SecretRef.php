<?php

namespace App\Pai\Security;

/**
 * 機密佔位符工具。智能體 / 工具設定只使用佔位符 {{vault:NAME}}，
 * 永遠不接觸明文；真正值由 {@see EgressGateway} 在 egress 注入。
 */
final class SecretRef
{
    public const PATTERN = '/\{\{vault:([a-zA-Z0-9_.\-]+)\}\}/';

    public static function placeholder(string $name): string
    {
        return '{{vault:'.$name.'}}';
    }

    public static function containsRef(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }
}
