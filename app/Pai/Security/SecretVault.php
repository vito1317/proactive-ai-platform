<?php

namespace App\Pai\Security;

/**
 * 機密金庫契約。
 *
 * 架構鐵律：明文憑證**只**能由網路層的 {@see EgressGateway} 在請求送出前取用，
 * 絕不可交給智能體 / LLM / 寫入日誌。實作可換成 HashiCorp Vault、AWS Secrets Manager 等。
 */
interface SecretVault
{
    public function has(string $name): bool;

    /** 取明文——僅供 EgressGateway 於 egress 注入時呼叫。 */
    public function get(string $name): ?string;

    public function put(string $name, string $value, ?string $description = null): void;

    public function forget(string $name): void;

    /** @return string[] 金鑰名稱（不含值）——供稽核 / 後台列示。 */
    public function names(): array;
}
