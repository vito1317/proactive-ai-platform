<?php

namespace App\Pai\Domains;

/**
 * 依 docs/SPEC.md v1 / docs/schema/domain-pack.schema.json 驗證 manifest 陣列。
 *
 * 回傳錯誤字串清單（空陣列代表通過）。純函式、無副作用，方便單元測試。
 */
final class DomainPackValidator
{
    private const KEBAB = '/^[a-z][a-z0-9-]*$/';

    /**
     * @param  mixed  $m  YAML 解析後的結構（理應為 array）
     * @return string[] 錯誤清單
     */
    public function validate(mixed $m): array
    {
        if (! is_array($m)) {
            return ['manifest 必須是 YAML 物件 (mapping)'];
        }

        $errors = [];
        $required = ['domain', 'coordinator', 'description', 'triggers', 'tools', 'agents', 'memory', 'risk_policy', 'contracts'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $m)) {
                $errors[] = "缺少必填欄位 `{$key}`";
            }
        }
        if ($errors !== []) {
            return $errors; // 缺頂層欄位時先不深入，避免噴一堆連鎖錯誤
        }

        // domain / coordinator：kebab-case
        foreach (['domain', 'coordinator'] as $key) {
            if (! is_string($m[$key]) || ! preg_match(self::KEBAB, $m[$key])) {
                $errors[] = "`{$key}` 必須為 kebab-case 字串";
            }
        }
        if (! is_string($m['description']) || $m['description'] === '') {
            $errors[] = '`description` 不可為空';
        }

        $this->validateTriggers($m['triggers'], $errors);
        $this->validateTools($m['tools'], $errors);
        $this->validateAgents($m['agents'], $errors);
        $this->validateMemory($m['memory'], $errors);
        $this->validateRiskPolicy($m['risk_policy'], $errors);
        $this->validateContracts($m['contracts'], $errors);

        return $errors;
    }

    private function validateTriggers(mixed $t, array &$errors): void
    {
        if (! is_array($t)) {
            $errors[] = '`triggers` 必須是物件';

            return;
        }
        $events = $t['events'] ?? [];
        $cron = $t['cron'] ?? [];
        if (! is_array($events) || ! is_array($cron)) {
            $errors[] = '`triggers.events` / `triggers.cron` 必須是陣列';

            return;
        }
        if ($events === [] && $cron === []) {
            $errors[] = '`triggers` 至少需有一個 events 或 cron';
        }
    }

    private function validateTools(mixed $tools, array &$errors): void
    {
        if (! is_array($tools) || $tools === []) {
            $errors[] = '`tools` 必須是非空陣列';

            return;
        }
        $allowedPerms = config('pai.tool_perms');
        $allowedRisk = config('pai.risk_levels');
        foreach ($tools as $i => $tool) {
            $at = "tools[{$i}]";
            if (! is_array($tool)) {
                $errors[] = "`{$at}` 必須是物件";

                continue;
            }
            if (! isset($tool['uri']) || ! is_string($tool['uri']) || ! str_starts_with($tool['uri'], 'mcp://')) {
                $errors[] = "`{$at}.uri` 必須以 mcp:// 開頭";
            }
            $perms = $tool['perms'] ?? null;
            if (! is_array($perms) || $perms === []) {
                $errors[] = "`{$at}.perms` 必須是非空陣列";
            } elseif (array_diff($perms, $allowedPerms) !== []) {
                $errors[] = "`{$at}.perms` 只能是 ".implode('/', $allowedPerms);
            }
            if (isset($tool['risk']) && ! in_array($tool['risk'], $allowedRisk, true)) {
                $errors[] = "`{$at}.risk` 只能是 ".implode('/', $allowedRisk);
            }
        }
    }

    private function validateAgents(mixed $agents, array &$errors): void
    {
        if (! is_array($agents)) {
            $errors[] = '`agents` 必須是物件';

            return;
        }
        $topologies = config('pai.topologies');
        if (! isset($agents['topology']) || ! in_array($agents['topology'], $topologies, true)) {
            $errors[] = '`agents.topology` 只能是 '.implode('/', $topologies);
        }
        $roster = $agents['roster'] ?? null;
        if (! is_array($roster) || $roster === []) {
            $errors[] = '`agents.roster` 必須是非空陣列';

            return;
        }
        foreach ($roster as $i => $a) {
            if (! is_array($a) || ! isset($a['name'], $a['role'])) {
                $errors[] = "`agents.roster[{$i}]` 必須含 name 與 role";
            }
        }
    }

    private function validateMemory(mixed $memory, array &$errors): void
    {
        if (! is_array($memory)) {
            $errors[] = '`memory` 必須是物件';

            return;
        }
        if (! isset($memory['namespace']) || ! is_string($memory['namespace']) || ! preg_match(self::KEBAB, $memory['namespace'])) {
            $errors[] = '`memory.namespace` 必須為 kebab-case 字串';
        }
        $knowledge = $memory['knowledge'] ?? null;
        if (! is_array($knowledge) || $knowledge === []) {
            $errors[] = '`memory.knowledge` 必須是非空陣列';

            return;
        }
        $types = config('pai.knowledge_types');
        foreach ($knowledge as $i => $k) {
            if (! is_array($k) || ! isset($k['type'], $k['source'])) {
                $errors[] = "`memory.knowledge[{$i}]` 必須含 type 與 source";

                continue;
            }
            if (! in_array($k['type'], $types, true)) {
                $errors[] = "`memory.knowledge[{$i}].type` 只能是 ".implode('/', $types);
            }
        }
    }

    private function validateRiskPolicy(mixed $rp, array &$errors): void
    {
        if (! is_array($rp)) {
            $errors[] = '`risk_policy` 必須是物件';

            return;
        }
        $levels = config('pai.autonomy_levels');
        if (! isset($rp['autonomy']) || ! in_array($rp['autonomy'], $levels, true)) {
            $errors[] = '`risk_policy.autonomy` 只能是 '.implode('/', $levels);
        }
        if (isset($rp['hitl_required']) && ! is_array($rp['hitl_required'])) {
            $errors[] = '`risk_policy.hitl_required` 必須是陣列';
        }
        if (isset($rp['rate_limits'])) {
            if (! is_array($rp['rate_limits'])) {
                $errors[] = '`risk_policy.rate_limits` 必須是物件';
            } else {
                foreach ($rp['rate_limits'] as $action => $limit) {
                    if (! is_string($limit) || ! preg_match('#^[0-9]+/(sec|min|hour|day)$#', $limit)) {
                        $errors[] = "`risk_policy.rate_limits.{$action}` 格式須為 數字/sec|min|hour|day";
                    }
                }
            }
        }
    }

    private function validateContracts(mixed $c, array &$errors): void
    {
        if (! is_array($c)) {
            $errors[] = '`contracts` 必須是物件';

            return;
        }
        if (! isset($c['output']) || ! is_string($c['output']) || ! str_ends_with($c['output'], '.schema.json')) {
            $errors[] = '`contracts.output` 必須是以 .schema.json 結尾的路徑';
        }
    }
}
