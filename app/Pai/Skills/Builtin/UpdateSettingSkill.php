<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Settings\Settings;
use App\Pai\Skills\Skill;

/** 修改一項平台設定（自我修改）。高風險。 */
class UpdateSettingSkill implements Skill
{
    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return 'update-setting';
    }

    public function description(): string
    {
        return '修改一項平台設定，例如切換 LLM 模型、調整 ReAct 步數、溫度、逾時等';
    }

    public function parameters(): array
    {
        return [
            'key' => '設定鍵，必須是有效鍵（如 llm.model、llm.temperature、react.max_steps、llm.max_tokens）',
            'value' => '要設定的新值',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $key = (string) ($args['key'] ?? '');
        if (! array_key_exists($key, Settings::FIELDS)) {
            $valid = implode('、', array_keys(Settings::FIELDS));

            return "無法修改：「{$key}」不是有效設定鍵。可用鍵：{$valid}";
        }
        $type = Settings::FIELDS[$key]['type'];
        $value = match ($type) {
            'int' => (int) $args['value'],
            'number' => (float) $args['value'],
            'bool' => filter_var($args['value'], FILTER_VALIDATE_BOOLEAN),
            default => (string) $args['value'],
        };
        \App\Pai\Safety\Checkpoint::setting($key, $this->settings->get($key), 'update-setting'); // #5 可回滾
        $this->settings->set($key, $value);
        $shown = $type === 'secret' ? '••••（已更新）' : $value;

        return "已更新設定 {$key} = {$shown}（即時生效）。";
    }
}
