<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Http;
use Throwable;

/** 開啟網址並抽取可讀文字。低風險（唯讀網路）。 */
class WebFetchSkill implements Skill
{
    public function name(): string
    {
        return 'web-fetch';
    }

    public function description(): string
    {
        return '開啟一個網址、抽取頁面的可讀文字內容（用於閱讀文章/文件/API 回應）';
    }

    public function parameters(): array
    {
        return [
            'url' => '要開啟的網址',
            'max_chars' => '最多回傳字元數（預設 6000）',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $url = trim((string) ($args['url'] ?? ''));
        if (! preg_match('#^https?://#i', $url)) {
            return '請提供合法的 http(s) 網址。';
        }
        $max = max(500, min(20000, (int) ($args['max_chars'] ?? 6000)));

        try {
            $resp = Http::timeout(20)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; PAI/1.0)'])->get($url);
        } catch (Throwable $e) {
            return "開啟失敗：{$e->getMessage()}";
        }
        if ($resp->failed()) {
            return "開啟失敗：HTTP {$resp->status()}";
        }

        $body = $resp->body();
        $type = $resp->header('Content-Type');
        if (! str_contains($type, 'html')) {
            return "（{$type}）\n".mb_substr(trim($body), 0, $max); // JSON/純文字直接回
        }

        // 去掉 script/style/標籤，壓縮空白 → 可讀文字
        $text = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $body);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return "{$url}\n".mb_substr($text, 0, $max).(mb_strlen($text) > $max ? '…（已截斷）' : '');
    }
}
