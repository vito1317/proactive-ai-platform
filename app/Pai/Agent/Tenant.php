<?php

namespace App\Pai\Agent;

/**
 * 當前回合的「租戶」（對話擁有者 user_id）。SkillRunner 在跑技能前設定，
 * 讓技能（如生圖/Firecrawl/FAL）能讀「該帳號自己的」設定/金鑰，達到完全分權。
 */
class Tenant
{
    private static ?int $id = null;

    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    public static function id(): ?int
    {
        return self::$id;
    }
}
