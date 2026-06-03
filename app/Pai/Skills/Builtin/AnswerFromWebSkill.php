<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Cognition\LlmClient;
use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 回答「需要查網路才知道」的問題：自動搜尋 → 讀取前幾筆網頁 → 用 AI 彙整成
 * 一段直接的答案（附來源）。回答而非丟搜尋連結。低風險。
 */
class AnswerFromWebSkill implements Skill
{
    public function __construct(private readonly LlmClient $llm) {}

    public function name(): string
    {
        return 'answer-from-web';
    }

    public function description(): string
    {
        return '回答需要查網路才知道的問題（天氣、新聞、價格、即時資訊…）：自動搜尋並閱讀網頁後，直接給出彙整答案＋來源。使用者問問題時優先用這個（而非只回連結的 web-search）';
    }

    public function parameters(): array
    {
        return ['question' => '使用者想知道答案的問題'];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $q = trim((string) ($args['question'] ?? ''));
        if ($q === '') {
            return '請提供問題。';
        }

        $results = $this->search($q, 4);
        if ($results === []) {
            return "我查不到「{$q}」的網路資料（搜尋來源可能暫時限流），請稍後再試或換個說法。";
        }

        // 讀取前 2 筆網頁內文，作為回答依據
        $corpus = '';
        $sources = [];
        foreach (array_slice($results, 0, 2) as $r) {
            $sources[] = $r['title'].' — '.$r['url'];
            $corpus .= "\n## {$r['title']}（{$r['url']}）\n".$this->fetchText($r['url'], 2500);
        }
        foreach (array_slice($results, 2) as $r) {
            $sources[] = $r['title'].' — '.$r['url'];
            $corpus .= "\n## {$r['title']}（{$r['url']}）\n{$r['snippet']}";
        }

        try {
            $answer = trim($this->llm->chat([
                ['role' => 'system', 'content' => '你是嚴謹的研究助理。只根據提供的網路資料，用繁體中文「直接回答問題」（不要列出一堆連結、不要說「請參考」）。若資料不足就說明缺什麼。答案精簡務實。'],
                ['role' => 'user', 'content' => "問題：{$q}\n\n網路資料：{$corpus}\n\n請直接給出答案。"],
            ], ['max_tokens' => 1500]));
        } catch (Throwable $e) {
            return "讀到資料但彙整時出錯：{$e->getMessage()}";
        }

        return ($answer !== '' ? $answer : '抱歉，依目前資料無法得出明確答案。')
            ."\n\n— 來源 —\n".implode("\n", array_map(fn ($s) => "・{$s}", $sources));
    }

    /** @return list<array{title:string,url:string,snippet:string}> */
    private function search(string $q, int $limit): array
    {
        try {
            $html = Http::timeout(15)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; PAI/1.0)'])
                ->asForm()->get('https://html.duckduckgo.com/html/', ['q' => $q])->body();
        } catch (Throwable) {
            return [];
        }
        preg_match_all('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $html, $links, PREG_SET_ORDER);
        preg_match_all('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/s', $html, $snips, PREG_SET_ORDER);
        $clean = fn ($s) => trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $out = [];
        foreach (array_slice($links, 0, $limit) as $i => $a) {
            $url = $a[1];
            if (preg_match('/uddg=([^&]+)/', $url, $m)) {
                $url = urldecode($m[1]);
            }
            $out[] = ['title' => $clean($a[2]), 'url' => $url, 'snippet' => isset($snips[$i]) ? $clean($snips[$i][1]) : ''];
        }

        return $out;
    }

    private function fetchText(string $url, int $max): string
    {
        try {
            $resp = Http::timeout(15)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; PAI/1.0)'])->get($url);
            if ($resp->failed()) {
                return '（無法讀取此頁）';
            }
            $body = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $resp->body());
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            return mb_substr($text, 0, $max);
        } catch (Throwable) {
            return '（讀取逾時）';
        }
    }
}
