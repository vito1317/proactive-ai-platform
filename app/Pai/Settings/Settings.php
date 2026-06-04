<?php

namespace App\Pai\Settings;

use Throwable;

/**
 * 設定存取層：DB 覆寫（pai_settings）疊在 config/pai.php 預設之上。
 *
 * get() 每次直接讀 DB，所以後台改動會即時生效（不必重啟 queue worker）。
 * 表不存在時優雅退回 config 預設。
 */
class Settings
{
    /** 後台可編輯的欄位定義（型別 / 預設來自 config）。 */
    public const FIELDS = [
        'llm.base_url' => ['label' => 'LLM 端點 (OpenAI 相容)', 'type' => 'string', 'group' => 'LLM'],
        'llm.api_key' => ['label' => 'AI API Token / 金鑰', 'type' => 'secret', 'group' => 'LLM'],
        'llm.model' => ['label' => '模型名稱', 'type' => 'string', 'group' => 'LLM'],
        'llm.temperature' => ['label' => 'Temperature', 'type' => 'number', 'group' => 'LLM', 'min' => 0, 'max' => 2, 'step' => 0.1],
        'llm.max_tokens' => ['label' => 'Max tokens（思考型模型需較大）', 'type' => 'int', 'group' => 'LLM', 'min' => 256, 'max' => 16384],
        'llm.timeout' => ['label' => '逾時 (秒)', 'type' => 'int', 'group' => 'LLM', 'min' => 10, 'max' => 600],
        'react.max_steps' => ['label' => 'ReAct 最大步數', 'type' => 'int', 'group' => 'ReAct', 'min' => 1, 'max' => 12],
        'react.reflect' => ['label' => '啟用自我反思', 'type' => 'bool', 'group' => 'ReAct'],
        'skills.allow_system_writes' => ['label' => '允許 AI 直接執行高風險自我修改技能（關閉時需對話確認）', 'type' => 'bool', 'group' => '技能'],
        'voice.stt_url' => ['label' => '語音轉文字 (STT) 端點', 'type' => 'string', 'group' => '語音'],
        'notify.webhook_url' => ['label' => 'Webhook URL (Slack/Discord)', 'type' => 'string', 'group' => '通知'],
        'notify.telegram.token' => ['label' => 'Telegram Bot Token', 'type' => 'secret', 'group' => '通知'],
        'notify.telegram.chat_id' => ['label' => 'Telegram Chat ID', 'type' => 'string', 'group' => '通知'],
        'notify.line.secret' => ['label' => 'LINE Channel Secret（雙向接收驗證用）', 'type' => 'secret', 'group' => '通知'],
        'notify.line.token' => ['label' => 'LINE Channel Access Token', 'type' => 'secret', 'group' => '通知'],
        'notify.line.to' => ['label' => 'LINE 推播對象 (userId/groupId)', 'type' => 'string', 'group' => '通知'],
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $row = PaiSetting::find($key);
        } catch (Throwable) {
            $row = null; // 表尚未建立 → 用 config
        }

        if ($row !== null) {
            return $row->value;
        }

        return config("pai.{$key}", $default);
    }

    public function set(string $key, mixed $value): void
    {
        PaiSetting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** 領域 autonomy 的有效值（後台覆寫優先，否則用領域包預設）。 */
    public function domainAutonomy(string $domain, string $default): string
    {
        $v = $this->get("domain.{$domain}.autonomy");

        return in_array($v, ['copilot', 'supervisor', 'autopilot'], true) ? $v : $default;
    }

    /**
     * 後台設定頁要顯示的欄位 + 目前值。
     *
     * @return list<array<string, mixed>>
     */
    public function editableFields(): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $meta) {
            $out[] = [...$meta, 'key' => $key, 'value' => $this->get($key)];
        }

        return $out;
    }
}
