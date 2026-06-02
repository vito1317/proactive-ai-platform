<?php

namespace App\Pai\Cognition;

use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 與 OpenAI 相容的 LLM 後端（預設本機 llama-server）對話。
 *
 * 提供 ReAct 迴圈所需的兩個原語：
 *  - chat()      取得純文字回覆
 *  - chatJson()  取得並解析模型輸出的 JSON 物件（容忍 code fence / 前後雜訊）
 */
class LlmClient
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * 核心呼叫：回傳結構化結果（含思考型模型的 reasoning_content）。
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, reasoning: string, finish_reason: ?string, usage: array}
     */
    public function complete(array $messages, array $opts = []): array
    {
        // 每次讀設定 → 後台調整即時生效
        $baseUrl = rtrim((string) $this->settings->get('llm.base_url'), '/');
        $model = (string) $this->settings->get('llm.model');
        $apiKey = (string) $this->settings->get('llm.api_key', 'sk-local');
        $timeout = (int) $this->settings->get('llm.timeout');

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $opts['temperature'] ?? (float) $this->settings->get('llm.temperature'),
                    'max_tokens' => $opts['max_tokens'] ?? (int) $this->settings->get('llm.max_tokens'),
                ]);
        } catch (Throwable $e) {
            throw new LlmException('LLM 後端連線失敗：'.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new LlmException("LLM 後端回應 {$response->status()}：".$response->body());
        }

        $choice = $response->json('choices.0') ?? [];
        $content = $choice['message']['content'] ?? null;
        $reasoning = $choice['message']['reasoning_content'] ?? '';
        $finish = $choice['finish_reason'] ?? null;

        if (! is_string($content)) {
            throw new LlmException('LLM 回應缺少 message.content');
        }

        // 思考型模型把 token 全花在推理而截斷 → content 為空
        if ($content === '' && $finish === 'length') {
            throw new LlmException('LLM 推理超出 max_tokens 而未產出答案；請提高 PAI_LLM_MAX_TOKENS。');
        }

        return [
            'content' => $content,
            'reasoning' => is_string($reasoning) ? $reasoning : '',
            'finish_reason' => $finish,
            'usage' => $response->json('usage') ?? [],
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function chat(array $messages, array $opts = []): string
    {
        return $this->complete($messages, $opts)['content'];
    }

    /**
     * 取得並解析模型輸出的單一 JSON 物件。
     *
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, array $opts = []): array
    {
        $raw = $this->chat($messages, $opts);

        return self::extractJson($raw);
    }

    /**
     * 從可能含 code fence 或前後說明文字的回覆中抽出第一個 JSON 物件。
     *
     * @return array<string, mixed>
     */
    public static function extractJson(string $raw): array
    {
        $text = trim($raw);

        // ```json ... ``` 或 ``` ... ```
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        // 取第一個 { 到最後一個 }（容忍前後雜訊）
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new LlmException('LLM 回應不含 JSON 物件：'.mb_substr($raw, 0, 200));
        }
        $json = substr($text, $start, $end - $start + 1);

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new LlmException('LLM JSON 解析失敗：'.mb_substr($json, 0, 200));
        }

        return $decoded;
    }
}
