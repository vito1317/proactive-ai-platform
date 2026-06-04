<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Settings\Settings;
use App\Pai\Skills\Skill;

/** 依自然語言描述產生 curl 一鍵安裝指令（自動帶入本實例 repo / AI 端點）。低風險。 */
class GenerateInstallCommandSkill implements Skill
{
    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return 'generate-install-command';
    }

    public function description(): string
    {
        return '產生一鍵安裝指令：使用者描述要怎麼裝（要不要 nginx、網域、port、systemd 服務、production），輸出可直接貼到終端機執行的 curl 指令';
    }

    public function parameters(): array
    {
        return [
            'with_nginx' => '是否設定 nginx（true/false）',
            'domain' => '網域（要 nginx 時需要）',
            'port' => 'nginx 監聽埠（預設 8083）',
            'with_systemd' => '是否安裝 systemd 服務（true/false，預設 true）',
            'prod' => '是否 production 安裝（true/false）',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $repo = (string) ($this->settings->get('install.repo_url') ?? config('pai.install.repo_url'));
        $llmUrl = (string) $this->settings->get('llm.base_url');
        $llmModel = (string) $this->settings->get('llm.model');

        $bool = fn ($v, $default = false) => filter_var($v ?? $default, FILTER_VALIDATE_BOOLEAN);
        $flags = [
            '--repo '.escapeshellarg($repo),
            '--llm-url '.escapeshellarg($llmUrl),
            '--llm-model '.escapeshellarg($llmModel),
        ];
        if ($bool($args['prod'] ?? null)) {
            $flags[] = '--prod';
        }
        if ($bool($args['with_systemd'] ?? null, true)) {
            $flags[] = '--with-systemd';
        }
        if ($bool($args['with_nginx'] ?? null)) {
            $domain = trim((string) ($args['domain'] ?? ''));
            if ($domain === '') {
                return '要設定 nginx 的話，請告訴我網域（domain）。';
            }
            $port = (int) ($args['port'] ?? 8083) ?: 8083;
            $flags[] = '--with-nginx --domain '.escapeshellarg($domain).' --port '.$port;
        }

        $cmd = "curl -fsSL {$base}/install.sh | bash -s -- ".implode(' ', $flags);

        return "這是你的一鍵安裝指令（在目標機器的終端機執行）：\n\n{$cmd}\n\n"
            .'說明：腳本會自動 git clone 原始碼、安裝相依、建 DB、建前端、寫好 AI 端點，'
            .'並依你的選項設定 nginx / systemd。安裝時會詢問管理員 Email 與密碼（或先設環境變數 ADMIN_EMAIL／ADMIN_PASSWORD 免互動）。';
    }
}
