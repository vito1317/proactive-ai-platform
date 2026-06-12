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
        $prompt = \App\Pai\Cognition\Prompts::render('extract-memory', ['user' => $u, 'assistant' => $this->assistantText]);

        try {
            $json = $llm->chatJson([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 512, 'tier' => 'small', 'temperature' => 0]);
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
