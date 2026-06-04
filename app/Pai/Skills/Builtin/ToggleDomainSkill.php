<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;

/** 停用 / 啟用一個領域包（rename packs/<d>.yaml ↔ .disabled）。高風險。 */
class ToggleDomainSkill implements Skill
{
    public function name(): string
    {
        return 'toggle-domain';
    }

    public function description(): string
    {
        return '停用或啟用一個監控/自動化領域包（停用後該領域不再被觸發）';
    }

    public function parameters(): array
    {
        return [
            'domain' => '領域代號，例如 sec-ir、dev-auto、log-ops',
            'action' => 'enable 或 disable',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $domain = basename((string) ($args['domain'] ?? '')); // 防路徑跳脫
        $action = (string) ($args['action'] ?? '');
        $active = base_path("packs/{$domain}.yaml");
        $disabled = "{$active}.disabled";

        if ($action === 'disable') {
            if (! is_file($active)) {
                return is_file($disabled) ? "領域「{$domain}」本來就已停用。" : "找不到領域「{$domain}」。";
            }
            rename($active, $disabled);

            return "已停用領域「{$domain}」🔕（重啟 worker 後完全生效）。";
        }
        if ($action === 'enable') {
            if (! is_file($disabled)) {
                return is_file($active) ? "領域「{$domain}」本來就是啟用中。" : "找不到已停用的領域「{$domain}」。";
            }
            rename($disabled, $active);

            return "已啟用領域「{$domain}」✅（重啟 worker 後完全生效）。";
        }

        return 'action 必須是 enable 或 disable。';
    }
}
