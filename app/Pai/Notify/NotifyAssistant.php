<?php

namespace App\Pai\Notify;

use App\Pai\Cognition\LlmClient;
use Throwable;

/**
 * 自然語言引導設定通知平台：從使用者訊息抽取 Telegram / LINE / webhook 的
 * token 與目標，並產生引導回覆（抓到什麼、還缺什麼、怎麼取得）。
 */
class NotifyAssistant
{
    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @return array{channel: string, fields: array<string,string>, reply: string}
     */
    public function extract(string $message): array
    {
        $prompt = <<<PROMPT
        你是通知設定助手。從使用者訊息中抽取要設定的通知平台與憑證。
        支援：telegram（需 token + chat_id）、line（需 channel access token + 推播對象 to）、webhook（需 url）。

        只輸出一個 JSON 物件（不要其他文字）：
        {"channel":"telegram|line|webhook|unknown",
         "token":"抓到的 token 或空",
         "chat_id":"telegram chat id 或空",
         "to":"line 推播對象 或空",
         "webhook_url":"webhook 網址 或空",
         "reply":"用繁體中文：說明已抓到哪些、還缺什麼；若缺則簡述如何取得（例如 Telegram 找 @BotFather 建立 bot 取得 token、用 @userinfobot 取得 chat id）"}

        使用者訊息：「{$message}」
        PROMPT;

        try {
            $out = LlmClient::extractJson($this->llm->chat([['role' => 'user', 'content' => $prompt]]));
        } catch (Throwable $e) {
            return ['channel' => 'unknown', 'fields' => [], 'reply' => '無法解析，請再描述一次（例如：我的 Telegram bot token 是 ...，chat id 是 ...）。'];
        }

        $channel = in_array($out['channel'] ?? '', ['telegram', 'line', 'webhook'], true) ? $out['channel'] : 'unknown';

        // 對應到 Settings 鍵（只取非空）
        $map = [
            'telegram' => ['notify.telegram.token' => $out['token'] ?? '', 'notify.telegram.chat_id' => $out['chat_id'] ?? ''],
            'line' => ['notify.line.token' => $out['token'] ?? '', 'notify.line.to' => $out['to'] ?? ''],
            'webhook' => ['notify.webhook_url' => $out['webhook_url'] ?? ''],
        ];
        $fields = array_filter($map[$channel] ?? [], fn ($v) => is_string($v) && trim($v) !== '');

        return [
            'channel' => $channel,
            'fields' => $fields,
            'reply' => (string) ($out['reply'] ?? ''),
        ];
    }
}
