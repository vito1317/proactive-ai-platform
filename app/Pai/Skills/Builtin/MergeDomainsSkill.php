<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Domains\DomainPackGenerator;
use App\Pai\Skills\Skill;

/** 把多個（職責重疊的）領域包整合成一個，並停用原本的。高風險。 */
class MergeDomainsSkill implements Skill
{
    public function __construct(private readonly DomainPackGenerator $generator) {}

    public function name(): string
    {
        return 'merge-domains';
    }

    public function description(): string
    {
        return '把兩個以上職責重疊的領域包整合成單一領域包（涵蓋全部觸發/工具/職責、去重），並停用原本的';
    }

    public function parameters(): array
    {
        return [
            'domains' => '要整合的領域代號（逗號分隔，至少兩個），例如 waf-monitor,waf-log-monitor',
            'name' => '整合後的新領域代號（選填，預設自動命名）',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $raw = is_array($args['domains'] ?? null) ? $args['domains'] : explode(',', (string) ($args['domains'] ?? ''));
        $domains = array_values(array_filter(array_map(fn ($d) => basename(trim((string) $d)), $raw)));
        if (count($domains) < 2) {
            return '請至少提供兩個要整合的領域代號（domains，逗號分隔）。';
        }

        $bundle = '';
        $found = [];
        foreach ($domains as $d) {
            $path = $this->packPath($d);
            if ($path === null) {
                return "找不到領域「{$d}」。";
            }
            $found[$d] = $path;
            $bundle .= "\n# === 領域 {$d} ===\n".file_get_contents($path);
        }
        $target = basename((string) ($args['name'] ?? '')) ?: ($domains[0].'-merged');

        $desc = "請把以下多個既有領域包整合成「一個」領域包，domain 設為「{$target}」。"
            ."要涵蓋所有來源領域的觸發條件、工具、子智能體與職責並去除重複；保留最嚴格的風險策略。\n{$bundle}";

        $res = $this->generator->generate($desc);
        if (! $res['valid']) {
            return '整合失敗：'.($res['errors'][0] ?? '產生的 manifest 不合法').'。可以再說清楚要保留哪些能力嗎？';
        }
        $domain = $res['manifest']['domain'] ?: $target;
        file_put_contents(base_path("packs/{$domain}.yaml"), $res['yaml']);

        // 停用原本的領域包（保留檔案為 .disabled，可還原）
        $disabled = [];
        foreach ($found as $d => $path) {
            if ($d !== $domain && is_file($path)) {
                rename($path, $path.'.disabled');
                $disabled[] = $d;
            }
        }

        return "已整合成新領域「{$domain}」🧩，並停用原本的：".implode('、', $disabled)
            .'。重啟 worker 後完全生效；到「領域包」頁可檢視整合結果。';
    }

    private function packPath(string $domain): ?string
    {
        foreach ([base_path("packs/{$domain}.yaml"), base_path("packs/{$domain}.yaml.disabled")] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }
}
