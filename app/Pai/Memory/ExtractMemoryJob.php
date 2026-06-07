<?php

namespace App\Pai\Memory;

use App\Pai\Cognition\LlmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 從一輪對話抽取「值得長期記住的使用者個人事實」並寫入長期記憶。
 * 背景執行（不擋語音回覆延遲）。只記持久的個人資訊，不記一次性任務內容。
 */
class ExtractMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $userText,
        public string $assistantText = '',
        public ?int $userId = null,
    ) {}

    public function handle(LlmClient $llm, UserMemoryStore $store): void
    {
        $u = trim($this->userText);
        if (mb_strlen($u) < 2) {
            return;
        }
        $prompt = <<<P
        從以下對話抽取「值得【長期】記住的使用者個人資訊」（住哪、稱呼/名字、家人、職業、長期偏好與口味、不吃/不喜歡的東西、慣用 App、固定習慣等）。
        【只記跨對話都成立的持久事實】，不要記一次性的任務、當下的問題、臨時需求（例如「幫我查台中天氣」「現在幾點」不要記）。
        若沒有值得長期記住的資訊，回空陣列。用台灣正體中文，每則精簡一句。

        使用者說：「{$u}」
        助理回：「{$this->assistantText}」

        只輸出 JSON：{"memories":[{"category":"identity|location|preference|dislike|contact|routine|fact","content":"一句話"}]}
        /no_think
        P;

        try {
            $json = LlmClient::extractJson($llm->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 512]));
            $items = $json['memories'] ?? [];
            if (! is_array($items)) {
                return;
            }
            foreach (array_slice($items, 0, 8) as $it) {
                $content = is_array($it) ? (string) ($it['content'] ?? '') : (string) $it;
                $cat = is_array($it) ? (string) ($it['category'] ?? 'fact') : 'fact';
                if ($content !== '') {
                    $store->remember($this->userId, $content, $cat);
                }
            }
        } catch (Throwable) {
            // 抽取失敗不影響主流程
        }
    }
}
