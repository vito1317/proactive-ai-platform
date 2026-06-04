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
    /** 全程心跳：任何 LLM HTTP 等待期間定期呼叫（TG/LINE 維持「輸入中」動畫用）。 */
    private static $heartbeat = null;

    public function __construct(private readonly Settings $settings) {}

    /** 設定（或清除）心跳回呼。worker 一次只跑一個 job，全域狀態安全。 */
    public static function setHeartbeat(?callable $cb): void
    {
        self::$heartbeat = $cb;
    }

    /** curl progress 選項：傳輸等待期間約每秒觸發，驅動心跳。 */
    private static function progressOption(): array
    {
        return self::$heartbeat ? ['progress' => function (): void {
            if (self::$heartbeat) {
                (self::$heartbeat)();
            }
        }] : [];
    }

    /**
     * 串流對話：以 SSE 逐 token 取得回覆。每個 content 片段呼叫 $onDelta。
     * 思考型模型的 reasoning_content 不傳給 $onDelta（僅累積），首個 content 片段前
     * 呼叫 $onReasoning(true) 一次以利前端顯示「思考中」。
     * $onTick 則在「每個」delta（含 reasoning）都會呼叫——供 TG/LINE 在生成期間
     * 持續補發「輸入中／載入中」動畫（heartbeat）。
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, reasoning: string}
     */
    public function stream(array $messages, callable $onDelta, ?callable $onReasoning = null, ?callable $onTick = null): array
    {
        $baseUrl = rtrim((string) $this->settings->get('llm.base_url'), '/');
        $model = (string) $this->settings->get('llm.model');
        $apiKey = (string) $this->settings->get('llm.api_key', 'sk-local');
        $timeout = (int) $this->settings->get('llm.timeout');

        $response = Http::timeout($timeout)->withToken($apiKey)
            ->withOptions(['stream' => true, ...self::progressOption()])
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'temperature' => (float) $this->settings->get('llm.temperature'),
                'max_tokens' => (int) $this->settings->get('llm.max_tokens'),
                'stream' => true,
            ]);

        $body = $response->toPsrResponse()->getBody();
        $content = '';
        $reasoning = '';
        $sawReasoning = false;
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(2048);
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);
                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    break 2;
                }
                $json = json_decode($data, true);
                $delta = $json['choices'][0]['delta'] ?? null;
                if (! is_array($delta)) {
                    continue;
                }
                if ($onTick) {
                    $onTick(); // heartbeat：reasoning 階段也持續觸發
                }
                if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                    $reasoning .= $delta['reasoning_content'];
                    if (! $sawReasoning && $onReasoning) {
                        $sawReasoning = true;
                        $onReasoning(true);
                    }
                }
                if (isset($delta['content']) && $delta['content'] !== '') {
                    $content .= $delta['content'];
                    $onDelta($delta['content']);
                }
            }
        }

        return ['content' => $content, 'reasoning' => $reasoning];
    }

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
        $timeout = (int) ($opts['timeout'] ?? $this->settings->get('llm.timeout'));

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->withOptions(self::progressOption())
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
