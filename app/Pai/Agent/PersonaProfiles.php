<?php

namespace App\Pai\Agent;

use App\Pai\Settings\Settings;

/**
 * Agent Profiles（人格 / 模式系統，重新實作 Hermes 風格的可自定性）。
 * 每個 profile：name + soul（常駐人格身分/語氣/規則）+ tools（可用工具白名單；'all'=全部）+ constraints（行為約束）。
 * per-user：每個帳號有自己的 profiles + 目前啟用的 profile，可隨時切換。soul/constraints 會注入所有管道的 system prompt。
 */
class PersonaProfiles
{
    public function __construct(private readonly Settings $settings) {}

    /** 預設 profile（使用者沒設定時）。 */
    private function defaultProfile(): array
    {
        return ['name' => '預設', 'soul' => '', 'tools' => 'all', 'constraints' => ''];
    }

    /** @return list<array{name:string,soul:string,tools:mixed,constraints:string}> */
    public function all(?int $userId): array
    {
        $p = $this->settings->get('agent.profiles', null, $userId);
        if (! is_array($p) || $p === []) {
            return [$this->defaultProfile()];
        }

        return array_values($p);
    }

    public function save(?int $userId, array $profiles): void
    {
        $clean = [];
        foreach ($profiles as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $tools = $p['tools'] ?? 'all';
            $clean[] = [
                'name' => mb_substr($name, 0, 40),
                'soul' => mb_substr((string) ($p['soul'] ?? ''), 0, 4000),
                'tools' => is_array($tools) ? array_values(array_map('strval', $tools)) : 'all',
                'constraints' => mb_substr((string) ($p['constraints'] ?? ''), 0, 2000),
            ];
        }
        if ($clean === []) {
            $clean = [$this->defaultProfile()];
        }
        $this->settings->set('agent.profiles', $clean, $userId);
    }

    public function activeName(?int $userId): string
    {
        return (string) $this->settings->get('agent.active_profile', '', $userId);
    }

    /** 目前啟用的 profile（找不到回第一個）。 */
    public function active(?int $userId): array
    {
        $name = $this->activeName($userId);
        $all = $this->all($userId);
        foreach ($all as $p) {
            if (($p['name'] ?? '') === $name) {
                return $p;
            }
        }

        return $all[0];
    }

    /** 切換啟用的 profile（依名稱，模糊比對）；成功回該 profile 名稱，找不到回 null。 */
    public function switchTo(?int $userId, string $name): ?string
    {
        $name = trim($name);
        foreach ($this->all($userId) as $p) {
            $pn = (string) ($p['name'] ?? '');
            if ($pn !== '' && (mb_strtolower($pn) === mb_strtolower($name) || str_contains($pn, $name) || str_contains($name, $pn))) {
                $this->settings->set('agent.active_profile', $pn, $userId);

                return $pn;
            }
        }

        return null;
    }

    /** 啟用 profile 的人格(soul) + 行為約束，組成要注入 system prompt 的文字（空字串=沒設定）。 */
    public function systemOverlay(?int $userId): string
    {
        $a = $this->active($userId);
        $parts = [];
        $soul = trim((string) ($a['soul'] ?? ''));
        $constraints = trim((string) ($a['constraints'] ?? ''));
        if ($soul !== '') {
            $parts[] = "【你的人格設定（最高優先，貫穿所有回應）】\n".$soul;
        }
        if ($constraints !== '') {
            $parts[] = "【行為約束（務必遵守）】\n".$constraints;
        }

        return implode("\n\n", $parts);
    }

    /** 啟用 profile 的可用工具白名單；null=全部（不限制）。 */
    public function allowedTools(?int $userId): ?array
    {
        $t = $this->active($userId)['tools'] ?? 'all';

        return is_array($t) && $t !== [] ? array_map('strval', $t) : null;
    }
}
