<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Settings\Settings;
use App\Pai\Skills\Skill;

/** 查看目前平台設定（secret 遮罩）。低風險。 */
class GetSettingsSkill implements Skill
{
    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return 'get-settings';
    }

    public function description(): string
    {
        return '查看目前平台設定值（LLM 端點/模型、通知、ReAct 參數等；密鑰會遮罩）';
    }

    public function parameters(): array
    {
        return [];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $lines = [];
        foreach (Settings::FIELDS as $key => $meta) {
            $val = $this->settings->get($key);
            if (($meta['type'] ?? '') === 'secret' && $val) {
                $val = '••••（已設定）';
            }
            $lines[] = "・{$meta['label']}（{$key}）= ".($val === null || $val === '' ? '（未設定）' : $val);
        }

        return "目前平台設定：\n".implode("\n", $lines);
    }
}
