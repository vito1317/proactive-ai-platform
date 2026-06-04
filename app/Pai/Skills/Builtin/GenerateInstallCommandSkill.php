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
            'include_secrets' => '是否把本實例的 AI 金鑰/語音密鑰一併帶入（true/false，預設 false）',
            'voice' => '是否帶入語音設定（STT/全雙工/人格）（true/false，預設依目前是否啟用全雙工）',
            'admin_email' => '免互動建立管理員的 Email（選填，搭配 admin_password）',
            'admin_password' => '免互動建立管理員的密碼（選填）',
            'set' => '額外要寫入 .env 的鍵值（物件，如 {"PAI_LLM_TIMEOUT":"240"}）',
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
        $arg = fn ($v) => escapeshellarg((string) $v);
        $flags = [
            '--repo '.$arg($repo),
            '--llm-url '.$arg($llmUrl),
            '--llm-model '.$arg($llmModel),
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
            $flags[] = '--with-nginx --domain '.$arg($domain).' --port '.$port;
        }

        // 機密：AI 金鑰 + 語音橋接密鑰（預設不帶，避免外洩；明確要求才帶入）
        $secretsNote = '';
        if ($bool($args['include_secrets'] ?? null)) {
            $apiKey = (string) $this->settings->get('llm.api_key');
            $voiceSecret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));
            if ($apiKey !== '' && $apiKey !== 'sk-local') {
                $flags[] = '--llm-key '.$arg($apiKey);
            }
            if ($voiceSecret !== '') {
                $flags[] = '--voice-secret '.$arg($voiceSecret);
            }
            $secretsNote = "\n⚠️ 指令含機密（AI 金鑰／語音密鑰），請只在私下傳遞、勿貼到公開處。";
        }

        // 語音設定（STT/全雙工/人格）—— 預設依目前是否啟用全雙工
        $wantVoice = $bool($args['voice'] ?? null, (bool) $this->settings->get('voice.fullduplex_enabled', false));
        if ($wantVoice) {
            $stt = (string) $this->settings->get('voice.stt_url', config('pai.voice.stt_url'));
            $fdUrl = (string) $this->settings->get('voice.fullduplex_url', config('pai.voice.fullduplex_url'));
            $prompt = (string) $this->settings->get('voice.system_prompt', config('pai.voice.system_prompt'));
            if ($stt !== '') {
                $flags[] = '--voice-stt-url '.$arg($stt);
            }
            if ($fdUrl !== '') {
                $flags[] = '--voice-fd-url '.$arg($fdUrl);
            }
            if ($prompt !== '') {
                $flags[] = '--voice-prompt '.$arg($prompt);
            }
        }

        // 免互動管理員
        if (trim((string) ($args['admin_email'] ?? '')) !== '') {
            $flags[] = '--admin-email '.$arg($args['admin_email']);
        }
        if (trim((string) ($args['admin_password'] ?? '')) !== '') {
            $flags[] = '--admin-password '.$arg($args['admin_password']);
        }

        // 任意額外 .env（--set KEY=VALUE，可多筆）
        $extra = $args['set'] ?? null;
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                if (is_string($k) && $k !== '') {
                    $flags[] = '--set '.$arg($k.'='.$v);
                }
            }
        }

        $cmd = "curl -fsSL {$base}/install.sh | bash -s -- ".implode(' ', $flags);

        return "這是你的一鍵安裝指令（在目標機器的終端機執行）：\n\n{$cmd}\n\n"
            .'說明：腳本會自動 git clone 原始碼、安裝相依、建 DB、建前端、寫好 AI 端點/語音設定，'
            .'並依你的選項設定 nginx / systemd。可用 --set KEY=VALUE 帶入任意 .env 設定。'
            .'未帶 --admin-email/--admin-password 時安裝會互動詢問管理員帳密。'.$secretsNote;
    }
}
