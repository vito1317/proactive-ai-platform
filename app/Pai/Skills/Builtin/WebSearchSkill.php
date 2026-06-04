<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Http;
use Throwable;

/** 上網搜尋（DuckDuckGo，免金鑰）。低風險（唯讀網路）。 */
class WebSearchSkill implements Skill
{
    public function name(): string
    {
        return 'web-search';
    }

    public function description(): string
    {
        return '上網搜尋資料，回傳前幾筆結果（標題 + 連結 + 摘要）';
    }

    public function parameters(): array
    {
        return [
            'query' => '搜尋關鍵字',
            'limit' => '回傳筆數（預設 5，上限 10）',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $q = trim((string) ($args['query'] ?? ''));
        if ($q === '') {
            return '請提供搜尋關鍵字。';
        }
        $limit = max(1, min(10, (int) ($args['limit'] ?? 5)));

        try {
            $html = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; PAI/1.0)'])
                ->asForm()->get('https://html.duckduckgo.com/html/', ['q' => $q])->body();
        } catch (Throwable $e) {
            return "搜尋失敗：{$e->getMessage()}";
        }

        preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $links, PREG_SET_ORDER);
        preg_match_all('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/s', $html, $snips, PREG_SET_ORDER);

        if ($links === []) {
            return "「{$q}」沒有解析到結果（搜尋來源可能暫時改版或限流）。";
        }

        $clean = fn ($s) => trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $lines = [];
        foreach (array_slice($links, 0, $limit) as $i => $a) {
            $url = $a[1];
            if (preg_match('/uddg=([^&]+)/', $url, $m)) {
                $url = urldecode($m[1]); // DDG 轉址 → 還原原網址
            }
            $snippet = isset($snips[$i]) ? $clean($snips[$i][1]) : '';
            $lines[] = ($i + 1).'. '.$clean($a[2])."\n   {$url}".($snippet !== '' ? "\n   {$snippet}" : '');
        }

        return "「{$q}」搜尋結果：\n".implode("\n", $lines);
    }
}
