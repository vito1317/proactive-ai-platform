<?php

namespace App\Pai\Cognition;

use Throwable;

/**
 * 後設路由：判斷使用者的自然語言屬於哪一類意圖，讓「指揮 AI」單一輸入框
 * 能自動分派——執行任務 / 新增領域 / 設定通知。
 */
class MetaRouter
{
    public const CATEGORIES = ['chat', 'task', 'new_domain', 'configure_notify'];

    public function __construct(private readonly LlmClient $llm) {}

    /** @return array{category: string, reason: string} */
    public function classify(string $message): array
    {
        $prompt = <<<PROMPT
        判斷使用者訊息屬於下列哪一類，只輸出 JSON：{"category":"chat|task|new_domain|configure_notify","reason":"一句話"}
        - chat：一般對話、提問、閒聊、問平台能做什麼、釐清需求（不需立即執行動作）
        - task：要 AI 立即執行/處理一件事（資安事件、修 bug、調查、處理日誌錯誤、開票…）
        - new_domain：想「新增一個持續性的監控/自動化領域」（描述一種長期職責，例如「監控 X 並自動 Y」）
        - configure_notify：要設定通知平台，訊息含 Telegram/LINE/Slack/webhook 的 token、chat id 或推播對象

        （訊息可能附帶先前對話脈絡；以最後一則使用者意圖為準。）
        使用者訊息：「{$message}」
        PROMPT;

        try {
            $out = LlmClient::extractJson($this->llm->chat([['role' => 'user', 'content' => $prompt]]));
        } catch (Throwable) {
            return ['category' => 'chat', 'reason' => '無法判斷，預設為對話']; // 安全預設：不誤觸動作
        }

        $cat = in_array($out['category'] ?? '', self::CATEGORIES, true) ? $out['category'] : 'chat';

        return ['category' => $cat, 'reason' => (string) ($out['reason'] ?? '')];
    }
}
