<?php

namespace App\Pai\Security;

/**
 * 用過即丟的隔離沙盒，執行 AI 動態生成的程式碼。
 * production 可替換為容器型沙盒（Vercel Sandbox 等），實作同介面即可。
 */
interface Sandbox
{
    /**
     * @param  string  $language  python|php
     * @param  string  $code  原始碼（透過 stdin 餵入，不落地到可掛載路徑）
     */
    public function run(string $language, string $code, int $timeoutSeconds = 10): SandboxResult;
}
