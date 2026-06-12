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
    public function __construct(private readonly LlmClient $llm, private readonly Notifier $notifier) {}

    /**
     * @return array{channel: string, fields: array<string,string>, reply: string}
     */
    public function extract(string $message): array
    {
        // 告知 LLM 目前已設定好的通道，避免「明明設好了還跟使用者要 token」
        $state = implode('、', array_keys(array_filter($this->notifier->configured()))) ?: '（無）';

        $prompt = \App\Pai\Cognition\Prompts::render('notify-assistant', ['state' => $state, 'message' => $message]);

        try {
            $out = $this->llm->chatJson([['role' => 'user', 'content' => $prompt]], ['tier' => 'small', 'temperature' => 0]);
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
