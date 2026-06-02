<?php

namespace App\Pai\Domains;

/**
 * 已載入領域包的記憶體內登錄處。主路由 (RootRouter) 透過它
 * 把事件 / 意圖分流到正確的領域協調者。
 *
 * 由 PaiServiceProvider 註冊為 singleton，啟動時載入一次。
 */
final class DomainRegistry
{
    /**
     * @param  array<string, DomainPack>  $packs  以 domain 鍵
     * @param  array<string, string[]>  $errors  載入失敗的檔案 => 錯誤
     */
    public function __construct(
        private array $packs = [],
        private array $errors = [],
    ) {}

    /** @return array<string, DomainPack> */
    public function all(): array
    {
        return $this->packs;
    }

    public function get(string $domain): ?DomainPack
    {
        return $this->packs[$domain] ?? null;
    }

    public function has(string $domain): bool
    {
        return isset($this->packs[$domain]);
    }

    public function count(): int
    {
        return count($this->packs);
    }

    /**
     * 訂閱了某事件主題的領域包（L1 分流用）。
     *
     * @return list<DomainPack>
     */
    public function forEvent(string $topic): array
    {
        return array_values(array_filter(
            $this->packs,
            static fn (DomainPack $p): bool => in_array($topic, $p->eventTopics(), true),
        ));
    }

    /** 載入期間被拒絕的檔案與原因（供稽核 / 診斷）。 */
    public function errors(): array
    {
        return $this->errors;
    }
}
