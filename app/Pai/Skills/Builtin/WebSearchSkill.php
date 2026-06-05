<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 上網搜尋（免金鑰）。低風險（唯讀網路）。
 * 多來源備援：Brave → DuckDuckGo html → DuckDuckGo lite（單一來源被限流/改版時自動換下一個）。
 */
class WebSearchSkill implements Skill
{
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

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

        foreach (['brave', 'ddgHtml', 'ddgLite'] as $source) {
            try {
                $rows = $this->{$source}($q, $limit);
            } catch (Throwable) {
                $rows = [];
            }
            if ($rows !== []) {
                $lines = [];
                foreach ($rows as $i => [$title, $url, $snippet]) {
                    $lines[] = ($i + 1).'. '.$title."\n   {$url}".($snippet !== '' ? "\n   {$snippet}" : '');
                }

                return "「{$q}」搜尋結果：\n".implode("\n", $lines);
            }
        }

        return "「{$q}」沒有解析到結果（各搜尋來源可能暫時限流，稍後再試）。";
    }

    private function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function brave(string $q, int $limit): array
    {
        $html = Http::timeout(15)
            ->withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'zh-TW,zh;q=0.9'])
            ->get('https://search.brave.com/search', ['q' => $q, 'country' => 'tw'])->body();

        $rows = [];
        foreach (array_slice(preg_split('/<div class="result-wrapper/', $html) ?: [], 1) as $block) {
            if (! preg_match('/<a href="(https?:\/\/[^"]+)"[^>]*>/', $block, $u)) {
                continue;
            }
            preg_match('/class="title[^"]*"[^>]*>(.*?)<\/div>/s', $block, $t);
            preg_match('/class="(?:generic-)?snippet\s[^"]*"[^>]*>(.*?)<\/div>/s', $block, $s);
            $title = $this->clean($t[1] ?? '');
            if ($title === '') {
                continue;
            }
            $rows[] = [$title, $u[1], mb_substr($this->clean($s[1] ?? ''), 0, 200)];
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function ddgHtml(string $q, int $limit): array
    {
        $html = Http::timeout(15)
            ->withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'zh-TW,zh;q=0.9'])
            ->asForm()->post('https://html.duckduckgo.com/html/', ['q' => $q])->body();

        preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $links, PREG_SET_ORDER);
        preg_match_all('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/s', $html, $snips, PREG_SET_ORDER);

        $rows = [];
        foreach (array_slice($links, 0, $limit) as $i => $a) {
            $url = $a[1];
            if (preg_match('/uddg=([^&]+)/', $url, $m)) {
                $url = urldecode($m[1]); // DDG 轉址 → 還原原網址
            }
            $rows[] = [$this->clean($a[2]), $url, mb_substr($this->clean($snips[$i][1] ?? ''), 0, 200)];
        }

        return $rows;
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function ddgLite(string $q, int $limit): array
    {
        $html = Http::timeout(15)
            ->withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'zh-TW,zh;q=0.9'])
            ->get('https://lite.duckduckgo.com/lite/', ['q' => $q])->body();

        preg_match_all('/<a[^>]*rel="nofollow"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $links, PREG_SET_ORDER);
        preg_match_all('/<td[^>]*class="result-snippet"[^>]*>(.*?)<\/td>/s', $html, $snips, PREG_SET_ORDER);

        $rows = [];
        foreach (array_slice($links, 0, $limit) as $i => $a) {
            $url = $a[1];
            if (preg_match('/uddg=([^&]+)/', $url, $m)) {
                $url = urldecode($m[1]);
            }
            $rows[] = [$this->clean($a[2]), $url, mb_substr($this->clean($snips[$i][1] ?? ''), 0, 200)];
        }

        return $rows;
    }
}
