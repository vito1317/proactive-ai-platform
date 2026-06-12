<?php

namespace App\Pai\Cognition;

use App\Pai\Chat\StopStreaming;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    /** 暫時性錯誤（連線失敗 / 429 / 5xx）額外重試次數。 */
    private const MAX_RETRIES = 2;

    /** 各次重試前的退避（微秒）：0.5s → 1.5s。 */
    private const RETRY_BACKOFF_US = [500_000, 1_500_000];

    /** 全程心跳：任何 LLM HTTP 等待期間定期呼叫（TG/LINE 維持「輸入中」動畫用）。 */
    private static $heartbeat = null;

    /** 心跳丟出中止訊號時設為 true，讓外層把 curl 中斷（cURL 42）轉回 StopStreaming。 */
    private static bool $aborted = false;

    public function __construct(private readonly Settings $settings) {}

    /** 設定（或清除）心跳回呼。worker 一次只跑一個 job，全域狀態安全。 */
    public static function setHeartbeat(?callable $cb): void
    {
        self::$heartbeat = $cb;
    }

    /**
     * curl progress 選項：傳輸等待期間約每秒觸發。
     * 心跳可丟 StopStreaming 來「即時中斷」連阻塞中的 LLM 請求（終止/插話用）。
     */
    private static function progressOption(): array
    {
        return self::$heartbeat ? ['progress' => function (): void {
            if (self::$heartbeat) {
                try {
                    (self::$heartbeat)();
                } catch (StopStreaming $e) {
                    self::$aborted = true; // curl 會以 error 42 中止；外層據此還原 StopStreaming
                    throw $e;
                }
            }
        }] : [];
    }

    /**
     * 依 tier 解析端點與模型（模型分層）：
     *  - $opts['tier'] === 'small' 且後台有設 llm.small_model → 用輕量模型跑
     *    分類/壓縮/萃取等小任務（延遲秒級）；未設定則自動退回主模型（行為不變）。
     *
     * @return array{base_url: string, model: string, api_key: string}
     */
    private function endpoint(?int $uid, array $opts): array
    {
        if (($opts['tier'] ?? 'main') === 'small') {
            $model = trim((string) $this->settings->get('llm.small_model', '', $uid));
            if ($model !== '') {
                $base = trim((string) $this->settings->get('llm.small_base_url', '', $uid));
                $key = trim((string) $this->settings->get('llm.small_api_key', '', $uid));

                return [
                    'base_url' => rtrim($base !== '' ? $base : $this->settings->llmBaseUrl($uid), '/'),
                    'model' => $model,
                    'api_key' => $key !== '' ? $key : (string) $this->settings->get('llm.api_key', 'sk-local', $uid),
                ];
            }
        }

        return [
            'base_url' => rtrim($this->settings->llmBaseUrl($uid), '/'),
            'model' => (string) $this->settings->get('llm.model', null, $uid),
            'api_key' => (string) $this->settings->get('llm.api_key', 'sk-local', $uid),
        ];
    }

    /**
     * 模板思考關閉時，模型仍會生成頻道標記（<|channel>thought / <channel|>）混進 content
     * → 統一剝除，避免顯示/朗讀出標記。
     */
    public static function stripChannelMarkers(string $s): string
    {
        return (string) preg_replace('/<\|?channel\|?>[a-z]*\n?/i', '', $s);
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
    public function stream(array $messages, callable $onDelta, ?callable $onReasoning = null, ?callable $onTick = null, ?callable $onThought = null): array
    {
        $uid = \App\Pai\Agent\Tenant::id();
        ['base_url' => $baseUrl, 'model' => $model, 'api_key' => $apiKey] = $this->endpoint($uid, []);
        $timeout = (int) $this->settings->get('llm.timeout', null, $uid);

        self::$aborted = false;
        try {
            $response = Http::timeout($timeout)->withToken($apiKey)
                ->withOptions(['stream' => true, ...self::progressOption()])
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => (float) $this->settings->get('llm.temperature', null, $uid),
                    'max_tokens' => (int) $this->settings->get('llm.max_tokens', null, $uid),
                    'stream' => true,
                ]);
        } catch (StopStreaming $e) {
            throw $e;
        } catch (Throwable $e) {
            if (self::$aborted) {
                throw new StopStreaming;
            }
            throw $e;
        }

        $body = $response->toPsrResponse()->getBody();
        $content = '';
        $reasoning = '';
        $sawReasoning = false;
        $buffer = '';
        $fbuf = '';  // 頻道標記過濾緩衝

        try {
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
                        if ($onThought) {
                            $onThought($delta['reasoning_content']);
                        }
                        if (! $sawReasoning && $onReasoning) {
                            $sawReasoning = true;
                            $onReasoning(true);
                        }
                    }
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        // 頻道標記過濾：標記可能跨 delta → 先進緩衝，末端疑似未完標記就扣住
                        $fbuf .= $delta['content'];
                        $fbuf = self::stripChannelMarkers($fbuf);
                        $holdAt = strrpos($fbuf, '<');
                        $emitNow = $holdAt !== false && strlen($fbuf) - $holdAt <= 24
                            ? substr($fbuf, 0, $holdAt)
                            : $fbuf;
                        if ($emitNow !== '') {
                            $content .= $emitNow;
                            $onDelta($emitNow);
                            $fbuf = substr($fbuf, strlen($emitNow));
                        }
                    }
                }
            }
        } catch (StopStreaming $e) {
            throw $e;
        } catch (Throwable $e) {
            if (self::$aborted) {
                throw new StopStreaming;
            }
            throw $e;
        }

        // 沖出過濾緩衝的殘尾（剝完標記後若還有真內容就補發）
        $tail = self::stripChannelMarkers($fbuf);
        if ($tail !== '') {
            $content .= $tail;
            $onDelta($tail);
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
        // 每次讀設定 → 後台調整即時生效（帶租戶 → per-account 供應商/金鑰；tier=small 走輕量模型）
        $uid = \App\Pai\Agent\Tenant::id();
        ['base_url' => $baseUrl, 'model' => $model, 'api_key' => $apiKey] = $this->endpoint($uid, $opts);
        $timeout = (int) ($opts['timeout'] ?? $this->settings->get('llm.timeout', null, $uid));
        $t0 = microtime(true);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $opts['temperature'] ?? (float) $this->settings->get('llm.temperature', null, $uid),
            'max_tokens' => $opts['max_tokens'] ?? (int) $this->settings->get('llm.max_tokens', null, $uid),
        ];

        // 暫時性錯誤（連線失敗 / 429 / 5xx）退避重試；使用者中止（心跳丟 StopStreaming）不重試
        self::$aborted = false;
        $response = null;
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->withOptions(self::progressOption())
                    ->post($baseUrl.'/chat/completions', $payload);
            } catch (Throwable $e) {
                if (self::$aborted || $attempt >= self::MAX_RETRIES) {
                    throw new LlmException('LLM 後端連線失敗：'.$e->getMessage(), previous: $e);
                }
                Log::warning('LLM 連線失敗，準備重試', ['attempt' => $attempt + 1, 'error' => $e->getMessage()]);
                usleep(self::RETRY_BACKOFF_US[$attempt] ?? 1_500_000);

                continue;
            }

            if ($response->failed()) {
                $status = $response->status();
                if (($status === 429 || $status >= 500) && $attempt < self::MAX_RETRIES) {
                    Log::warning('LLM 後端暫時性錯誤，準備重試', ['attempt' => $attempt + 1, 'status' => $status]);
                    usleep(self::RETRY_BACKOFF_US[$attempt] ?? 1_500_000);

                    continue;
                }
                throw new LlmException("LLM 後端回應 {$status}：".$response->body());
            }

            break;
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

        $usage = $response->json('usage') ?? [];
        // #9 用量觀測：記每日 calls / tokens / 累計延遲（失敗不影響主流程）
        try {
            \App\Pai\Cognition\LlmUsage::record(
                (int) ($usage['prompt_tokens'] ?? 0),
                (int) ($usage['completion_tokens'] ?? 0),
                (int) round((microtime(true) - $t0) * 1000),
            );
        } catch (Throwable) {
        }

        return [
            'content' => self::stripChannelMarkers($content),
            'reasoning' => is_string($reasoning) ? $reasoning : '',
            'finish_reason' => $finish,
            'usage' => $usage,
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
     * 解析失敗時把原輸出與錯誤回饋給模型重試一次（要求只輸出純 JSON）。
     *
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, array $opts = []): array
    {
        $raw = $this->chat($messages, $opts);

        try {
            return self::extractJson($raw);
        } catch (LlmException $e) {
            Log::warning('LLM JSON 解析失敗，回饋模型重試一次', ['error' => $e->getMessage()]);
            $retry = [
                ...$messages,
                ['role' => 'assistant', 'content' => $raw],
                ['role' => 'user', 'content' => '上面的輸出無法解析成 JSON。請重新回答：只輸出「一個」合法的 JSON 物件，不要 code fence、不要任何說明文字。'],
            ];

            return self::extractJson($this->chat($retry, $opts));
        }
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

        // 括號配對掃描：取第一個「平衡」的 JSON 物件（容忍前後雜訊與多個物件並存）
        $balanced = self::firstBalancedObject($text);
        if ($balanced !== null) {
            $decoded = json_decode($balanced, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 退而求其次：第一個 { 到最後一個 }（舊行為，捕捉跨段雜訊的殘餘案例）
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new LlmException('LLM 後端回應不含 JSON 物件：'.mb_substr($raw, 0, 200));
        }
        $json = substr($text, $start, $end - $start + 1);

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new LlmException('LLM JSON 解析失敗：'.mb_substr($json, 0, 200));
        }

        return $decoded;
    }

    /**
     * 從文字中掃出第一個括號平衡的 {...} 區段（正確處理字串字面值與跳脫字元）。
     * 找不到完整平衡區段時回傳 null。
     */
    private static function firstBalancedObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escaped = false;
        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $c = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
