<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Settings\Settings;
use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Http;
use Throwable;

/** 用 Firecrawl 高品質抓取網頁內容（轉成乾淨 markdown，比一般 fetch 更能拿到動態網站內容）。需 firecrawl.api_key。 */
class FirecrawlScrapeSkill implements Skill
{
    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return 'firecrawl-scrape';
    }

    public function description(): string
    {
        return '用 Firecrawl 抓取單一網址的乾淨內容（markdown）。url=要抓的網址。適合需要完整正文/動態網站時。';
    }

    public function parameters(): array
    {
        return ['url' => '要抓取的網址'];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $url = trim((string) ($args['url'] ?? ''));
        if ($url === '') {
            return '請提供要抓取的網址。';
        }
        $key = (string) $this->settings->get('firecrawl.api_key', '', \App\Pai\Agent\Tenant::id());
        if ($key === '') {
            return '尚未設定 Firecrawl API Key（後台「設定 → 供應商」）。可改用 web-fetch / answer-from-web。';
        }
        try {
            $resp = Http::timeout(60)->withToken($key)->post('https://api.firecrawl.dev/v1/scrape', [
                'url' => $url, 'formats' => ['markdown'], 'onlyMainContent' => true,
            ]);
            if (! $resp->successful()) {
                return 'Firecrawl 抓取失敗（HTTP '.$resp->status().'）：'.mb_substr($resp->body(), 0, 200);
            }
            $md = (string) ($resp->json('data.markdown') ?? '');

            return $md !== '' ? mb_substr($md, 0, 8000) : '（Firecrawl 沒抓到內容）';
        } catch (Throwable $e) {
            return 'Firecrawl 抓取失敗：'.$e->getMessage();
        }
    }
}
