<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Cognition\LlmClient;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * 全雙工語音的「指揮大腦」入口：voice_server (:8891) 在每一輪把使用者語音轉成
 * 文字後 POST 到這裡，本端用與聊天室完全相同的 agentic 技能引擎處理（可實際操控
 * 系統），回傳要朗讀的文字 + 活動步驟。語音因此成為「能操控系統」的另一個頻道。
 *
 * 用共用密鑰（X-Voice-Secret）驗證，因為 voice_server 不是登入使用者。
 */
class VoiceAgentController extends Controller
{
    public function __construct(
        private readonly ChatResponder $responder,
        private readonly LlmClient $llm,
        private readonly Settings $settings,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // 共用密鑰驗證（優先讀 Settings → 可由 AI / 後台即時調整，退回 config 預設）
        $secret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Voice-Secret'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'session' => ['nullable', 'string', 'max:128'],
            'node' => ['nullable', 'string', 'max:64'],   // 發指令的當前裝置（手機節點名）
            'geo' => ['nullable', 'array'],
            'geo.lat' => ['required_with:geo', 'numeric'],
            'geo.lng' => ['required_with:geo', 'numeric'],
        ]);

        $transcript = trim($data['transcript']);
        if ($transcript === '') {
            return response()->json(['reply' => '', 'steps' => [], 'conversation_id' => $data['conversation_id'] ?? null]);
        }

        $conv = $this->resolveConversation($data['conversation_id'] ?? null, $data['session'] ?? null);
        $this->rememberDevice($conv, $data['node'] ?? null);   // 記住「當前裝置」→ 預設操作節點
        $geo = $data['geo'] ?? null;
        $geoPlace = $geo ? $this->reverseGeocode((float) $geo['lat'], (float) $geo['lng']) : '';
        $conv->addMessage('user', $transcript, array_filter([
            'source' => 'voice', 'geo' => $geo, 'geo_place' => $geoPlace !== '' ? $geoPlace : null,
        ]));

        // 直達指令：明確的「打開/開啟 X」直接跑 open-app，不繞 LLM（快又不會被反問）
        if ($direct = $this->directCommand($transcript, $geo, $conv)) {
            $conv->addMessage('assistant', $direct['reply'], array_merge($direct['meta'], ['source' => 'voice']));

            return response()->json([
                'reply' => $direct['reply'],          // 顯示用（含技術細節）
                'speech' => $direct['speech'] ?? $direct['reply'], // 朗讀用（乾淨口語）
                'steps' => $direct['steps'] ?? [$direct['step'] ?? '⚡ 直接執行'],
                'meta' => $direct['meta'],
                'conversation_id' => $conv->id,
            ] + $this->ttsMeta());
        }

        // 重型多步任務（比價/研究/分析/規劃…）→ 在背景連續操作，語音先回快ack，完成後通知
        // （這類在本地思考模型上要數分鐘，同步等會逾時 504）
        if ($this->isHeavyTask($transcript)) {
            $event = \App\Pai\Perception\PaiEvent::create([
                'source' => 'voice', 'topic' => 'console.request',
                'payload' => ['message' => $transcript, 'conversation_id' => $conv->id],
                'status' => \App\Pai\Perception\EventStatus::Received,
            ]);
            \App\Pai\Cognition\RouteCommandJob::dispatch($event->id);
            $ack = '好的，這個我需要大約一分鐘查資料、整理，請先別關掉，弄好我直接念給你。';
            $conv->addMessage('assistant', $ack, ['source' => 'voice', 'category' => 'task', 'event_id' => $event->id]);

            return response()->json([
                'reply' => $ack, 'speech' => $ack,
                'steps' => ['🧠 背景連續操作中…'],
                'meta' => ['category' => 'task', 'background' => true, 'event_id' => $event->id],
                'conversation_id' => $conv->id,
            ]);
        }

        // 用與 SSE / TG / LINE 相同的路由 → 可閒聊也可實際跑技能操控系統
        $steps = [];
        $onStep = function (string $t) use (&$steps) {
            $steps[] = $t;
        };

        try {
            $r = $this->responder->route($conv, $transcript, $onStep);
            $reply = $r['stream']
                ? trim($this->llm->chat($r['messages']))
                : (string) $r['reply'];
            $meta = $r['stream'] ? ['category' => 'chat'] : ($r['meta'] ?? []);
        } catch (Throwable $e) {
            $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
            $meta = ['error' => true];
        }

        if ($reply === '') {
            $reply = '我沒有產生回覆，請再說一次。';
        }

        $reply = $this->utf8($reply); // LLM/工具輸出可能夾壞 UTF-8 → json_encode 會 500
        $conv->addMessage('assistant', $reply, array_merge($meta, ['source' => 'voice', 'trace' => $steps]));
        $this->maybeShowDoc($transcript, $reply);
        \App\Pai\Memory\ExtractMemoryJob::dispatch($transcript, $reply, $conv->user_id); // 背景抽取長期記憶

        return response()->json([
            'reply' => $reply,
            'speech' => $this->speechClean($reply), // 朗讀用：去掉指令/路徑/網址/emoji，避免 TTS 念出怪聲
            'steps' => array_map(fn ($s) => $this->utf8($s), $steps),
            'meta' => $meta,
            'conversation_id' => $conv->id,
        ] + $this->ttsMeta());
    }

    /** 該帳號選的 TTS 引擎/音色 + 從長期記憶抽出的語音字典 → 帶給 voice_server（每輪回應都附）。 */
    private function ttsMeta(): array
    {
        return [
            'tts_engine' => (string) ($this->settings->get('voice.tts_engine', 'edge', $this->turnOwnerId) ?: 'edge'),
            'tts_speaker' => (string) ($this->settings->get('voice.tts_speaker', 'Vivian', $this->turnOwnerId) ?: 'Vivian'),
            'vocab' => $this->speechVocab(),
        ];
    }

    /** 從長期記憶抽出關鍵詞（英文名/聯絡人/稱呼/地點）給 Whisper 當辨識字典，提升專有名詞正確率。 */
    private function speechVocab(): string
    {
        $uid = $this->turnOwnerId;
        if ($uid === null) {
            return '';
        }
        try {
            $mems = (string) \App\Pai\Memory\UserMemory::where('user_id', $uid)->limit(80)->pluck('content')->implode(' ');
        } catch (\Throwable) {
            return '';
        }
        $terms = [];
        // 英文/數字詞（Ian、Vivian、LINE、Rex Chang…）
        if (preg_match_all('/[A-Za-z][A-Za-z0-9]{1,}(?:\s[A-Z][A-Za-z]+)?/u', $mems, $m)) {
            $terms = array_merge($terms, $m[0]);
        }
        // 引號內的名字/稱呼（「蔡サイ」「王經理」…）
        if (preg_match_all('/[「『]([^」』]{1,10})[」』]/u', $mems, $mm)) {
            $terms = array_merge($terms, $mm[1]);
        }
        $terms = array_values(array_unique(array_filter(array_map('trim', $terms), fn ($t) => mb_strlen($t) >= 2)));

        return implode('、', array_slice($terms, 0, 40));
    }

    /** 天氣問答：解析地點+時間範圍 → open-meteo 預報 → 口語摘要。無法判斷回 null（交給 LLM）。 */
    private function weatherAnswer(string $t, ?array $geo): ?array
    {
        $place = null;
        $coord = null;
        if (preg_match('/(台北|臺北|新北|桃園|桃园|台中|臺中|台南|臺南|高雄|基隆|新竹|苗栗|彰化|南投|雲林|云林|嘉義|嘉义|屏東|屏东|宜蘭|宜兰|花蓮|花莲|台東|台东|澎湖|金門|金门|馬祖|马祖)/u', $t, $m)) {
            $place = $m[1];
            $coord = $this->geocodePlace($place.'市') ?? $this->geocodePlace($place);
        } elseif ($geo !== null) {
            $place = '你這裡';
            $coord = ['lat' => $geo['lat'], 'lng' => $geo['lng']];
        }
        if ($coord === null) {
            return null;
        }
        [$from, $to, $label] = $this->weatherRange($t);
        try {
            $daily = \Illuminate\Support\Facades\Http::timeout(8)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $coord['lat'], 'longitude' => $coord['lng'],
                    'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                    'timezone' => 'Asia/Taipei', 'forecast_days' => 16,
                ])->json('daily');
        } catch (Throwable) {
            return null;
        }
        if (! is_array($daily) || empty($daily['time'])) {
            return null;
        }
        $rain = [];
        $tmin = null;
        $tmax = null;
        $wk = ['日', '一', '二', '三', '四', '五', '六'];
        foreach ($daily['time'] as $i => $d) {
            if ($d < $from || $d > $to) {
                continue;
            }
            $tmax = max($tmax ?? -99, (float) ($daily['temperature_2m_max'][$i] ?? -99));
            $tmin = $tmin === null ? (float) ($daily['temperature_2m_min'][$i] ?? 99) : min($tmin, (float) ($daily['temperature_2m_min'][$i] ?? 99));
            $p = $daily['precipitation_probability_max'][$i] ?? null;
            if ($p !== null && (int) $p >= 40) {
                $rain[] = '週'.$wk[\Carbon\Carbon::parse($d)->dayOfWeek]."（{$p}%）";
            }
        }
        if ($tmin === null) {
            return null;
        }
        $temp = '氣溫大約 '.round($tmin).' 到 '.round($tmax).' 度';
        if ($from === $to) {
            // 單日問法：「明天會下雨嗎」→ 直接講機率，不要說「有 1 天」
            $p = $rain !== [] ? (int) preg_replace('/\D/', '', $rain[0]) : 0;
            $speech = $rain !== []
                ? "{$label}{$place}很可能下雨（降雨機率 {$p}%），{$temp}，記得帶傘。"
                : "{$label}{$place}應該不太會下雨，{$temp}。";
        } else {
            $speech = $rain === []
                ? "{$label}{$place}降雨機率不高，{$temp}，放心安排行程。"
                : "{$label}{$place}有 ".count($rain).' 天可能下雨：'.implode('、', $rain)."；{$temp}，記得帶傘。";
        }

        // 把真實預報資料丟給 PAI 的腦彙整成完整自然的摘要（失敗就用上面的模板句）
        $wk2 = ['日', '一', '二', '三', '四', '五', '六'];
        $rows = [];
        foreach ($daily['time'] as $i => $d) {
            if ($d < $from || $d > $to) {
                continue;
            }
            $rows[] = $d.'（週'.$wk2[\Carbon\Carbon::parse($d)->dayOfWeek].'）：降雨機率 '
                .($daily['precipitation_probability_max'][$i] ?? '?').'%，'
                .round((float) ($daily['temperature_2m_min'][$i] ?? 0)).'～'
                .round((float) ($daily['temperature_2m_max'][$i] ?? 0)).' 度';
        }
        $reply = "🌦 {$label}{$place}天氣：".($rain === [] ? '降雨機率不高' : '可能下雨 '.implode('、', $rain))."，{$temp}。";
        try {
            $summary = trim($this->llm->chat([
                ['role' => 'system', 'content' => '你是天氣播報助理。根據提供的「真實預報資料」用台灣正體中文寫一段自然、完整、口語的天氣摘要（含穿著/帶傘/行程建議），3-5 句。只能依資料說話，不要編造。'],
                ['role' => 'user', 'content' => "問題：{$t}\n地點：{$place}\n範圍：{$label}\n預報資料：\n".implode("\n", $rows)],
            ], ['max_tokens' => 512, 'timeout' => 30]));
            if ($summary !== '') {
                $reply = "🌦 {$summary}";
                $speech = $this->speechClean($summary);
            }
        } catch (Throwable) {
            // LLM 掛了 → 模板句照用
        }

        return [
            'reply' => $reply."\n\n（資料：open-meteo 實時預報）",
            'speech' => $speech,
            'meta' => ['category' => 'skill', 'skill' => 'weather', 'direct' => true, 'action' => 'weather'],
            'step' => "🌦 天氣：{$place}・{$label}",
            'steps' => ["🌦 取得氣象資料（open-meteo）：{$place}・{$label}", '🧠 AI 彙整天氣摘要…'],
        ];
    }

    /** 「今天/明天/後天/這週/週末/下週」→ [起日, 迄日, 標籤]（Asia/Taipei）。 */
    private function weatherRange(string $t): array
    {
        $now = now('Asia/Taipei')->startOfDay();
        if (preg_match('/下週|下周/u', $t)) {
            $s = $now->copy()->next(\Carbon\CarbonInterface::MONDAY);

            return [$s->toDateString(), $s->copy()->addDays(6)->toDateString(), '下週'];
        }
        if (preg_match('/週末|周末/u', $t)) {
            $s = $now->isWeekend() ? $now->copy() : $now->copy()->next(\Carbon\CarbonInterface::SATURDAY);

            return [$s->toDateString(), $s->copy()->next(\Carbon\CarbonInterface::SUNDAY)->toDateString(), '週末'];
        }
        if (preg_match('/這週|这周|本週|本周/u', $t)) {
            return [$now->toDateString(), $now->copy()->endOfWeek(\Carbon\CarbonInterface::SUNDAY)->toDateString(), '這週'];
        }
        if (str_contains($t, '後天') || str_contains($t, '后天')) {
            $d = $now->copy()->addDays(2);

            return [$d->toDateString(), $d->toDateString(), '後天'];
        }
        if (str_contains($t, '明天')) {
            $d = $now->copy()->addDay();

            return [$d->toDateString(), $d->toDateString(), '明天'];
        }
        if (str_contains($t, '今天')) {
            return [$now->toDateString(), $now->toDateString(), '今天'];
        }

        return [$now->toDateString(), $now->copy()->addDays(6)->toDateString(), '接下來幾天'];
    }

    /** 地名 → 座標（Nominatim/OSM，臺灣優先；快取一天）。失敗回 null。 */
    private function geocodePlace(string $q): ?array
    {
        $key = 'geo:fwd:'.md5($q);
        $hit = \Illuminate\Support\Facades\Cache::remember($key, 86400, function () use ($q) {
            try {
                $r = \Illuminate\Support\Facades\Http::timeout(6)
                    ->withHeaders(['User-Agent' => 'pai-voice/1.0'])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $q, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'tw', 'accept-language' => 'zh-TW',
                    ])->json();

                return isset($r[0]['lat']) ? ['lat' => (float) $r[0]['lat'], 'lng' => (float) $r[0]['lon']] : ['miss' => true];
            } catch (\Throwable) {
                return ['miss' => true];
            }
        });

        return isset($hit['miss']) ? null : $hit;
    }

    /** OSRM 公共路由：兩點開車距離/時間。回 [公里, 分鐘] 或 null。 */
    private function routeEstimate(array $from, array $to): ?array
    {
        try {
            $r = \Illuminate\Support\Facades\Http::timeout(6)
                ->withHeaders(['User-Agent' => 'pai-voice/1.0'])
                ->get("https://router.project-osrm.org/route/v1/driving/{$from['lng']},{$from['lat']};{$to['lng']},{$to['lat']}", ['overview' => 'false'])
                ->json();
            $route = $r['routes'][0] ?? null;
            if ($route === null) {
                return null;
            }

            return [round($route['distance'] / 1000, 1), (int) ceil($route['duration'] / 60)];
        } catch (\Throwable) {
            return null;
        }
    }

    /** 反查地名（Nominatim/OSM，免金鑰；快取一天）。失敗回空字串。 */
    private function reverseGeocode(float $lat, float $lng): string
    {
        $key = 'geo:rev:'.round($lat, 3).','.round($lng, 3);

        return (string) \Illuminate\Support\Facades\Cache::remember($key, 86400, function () use ($lat, $lng) {
            try {
                $r = \Illuminate\Support\Facades\Http::timeout(5)
                    ->withHeaders(['User-Agent' => 'pai-voice/1.0'])
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'lat' => $lat, 'lon' => $lng, 'format' => 'json', 'accept-language' => 'zh-TW', 'zoom' => 16,
                    ]);

                return (string) ($r->json('display_name') ?? '');
            } catch (\Throwable) {
                return '';
            }
        });
    }

    /** 清掉無效 UTF-8（LLM 截斷的多位元組字會讓 json_encode 直接炸 500）。 */
    private function utf8(string $s): string
    {
        return (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    /** 使用者要求「輸出文檔/整理成文件」且回覆夠長 → 自動推到在線手機畫面彈出顯示。 */
    private function maybeShowDoc(string $transcript, string $reply): void
    {
        if (mb_strlen($reply) < 120) {
            return;
        }
        if (! preg_match('/(文檔|文件|報告|报告|整理成|輸出|输出|報表|报表|文案|清單|清单|表格|筆記|笔记|顯示在|显示在)/u', $transcript)) {
            return;
        }
        try {
            foreach (\App\Pai\Mcp\ReverseBus::onlineNodes() as $n) {
                \App\Pai\Mcp\ReverseBus::fire($n, 'show_document', ['title' => 'PAI 文件', 'content' => $reply]);
            }
        } catch (Throwable) {
            // 無在線手機 / 推送失敗 → 略過（回覆仍在字幕）
        }
    }

    /**
     * SSE 串流版：邊生成邊回（voice_server 收到一句念一句，不用等全部跑完）。
     * 事件：step（執行步驟）/ delta（回覆文字片段）/ done（完整結果）。
     */
    /**
     * 主動對語音 session 念一句話（開車模式念通知、主動問目的地用）。
     * 手機用 X-Register-Secret 認證；轉呼 voice_server /voice/push（by session）。
     */
    public function announce(Request $request): \Illuminate\Http\JsonResponse
    {
        $secret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));
        $authed = $request->user() !== null
            || ($secret !== '' && hash_equals($secret, (string) $request->header('X-Voice-Secret')))
            || \App\Http\Controllers\GatewayController::ownerFromRequest($request) !== null;
        if (! $authed) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $request->validate([
            'session' => ['required', 'string', 'max:128'],
            'text' => ['required', 'string', 'max:1000'],
            'progress' => ['nullable', 'boolean'],
        ]);
        try {
            $url = (string) config('pai.voice.push_url', 'http://127.0.0.1:8891/voice/push');
            $resp = \Illuminate\Support\Facades\Http::timeout(60)->post($url, [
                'session' => $data['session'],
                'text' => $data['text'],
                'progress' => (bool) ($data['progress'] ?? false),
                'secret' => $secret,
            ]);

            return response()->json(['ok' => (bool) ($resp->json('ok') ?? false), 'voice' => $resp->json()]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function stream(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $secret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Voice-Secret')), 401);

        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'session' => ['nullable', 'string', 'max:128'],
            'node' => ['nullable', 'string', 'max:64'],   // 發指令的當前裝置
            'geo' => ['nullable', 'array'],
            'geo.lat' => ['required_with:geo', 'numeric'],
            'geo.lng' => ['required_with:geo', 'numeric'],
        ]);

        return response()->stream(function () use ($data) {
            $emit = function (string $event, array $payload): void {
                echo "event: {$event}\ndata: ".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            $transcript = trim($data['transcript']);
            if ($transcript === '') {
                $emit('done', ['reply' => '', 'speech' => '', 'conversation_id' => $data['conversation_id'] ?? null]);

                return;
            }
            $conv = $this->resolveConversation($data['conversation_id'] ?? null, $data['session'] ?? null);
            $this->rememberDevice($conv, $data['node'] ?? null);   // 記住當前裝置
            $geo = $data['geo'] ?? null;
            $geoPlace = $geo ? $this->reverseGeocode((float) $geo['lat'], (float) $geo['lng']) : '';
            $conv->addMessage('user', $transcript, array_filter([
                'source' => 'voice', 'geo' => $geo, 'geo_place' => $geoPlace !== '' ? $geoPlace : null,
            ]));

            // 直達指令／重型背景任務：結果立即一次回（本來就快）
            if ($direct = $this->directCommand($transcript, $geo, $conv)) {
                $conv->addMessage('assistant', $direct['reply'], array_merge($direct['meta'], ['source' => 'voice']));
                foreach ($direct['steps'] ?? [$direct['step'] ?? '⚡ 直接執行'] as $st) {
                    $emit('step', ['text' => $st]);
                }
                $emit('done', [
                    'reply' => $direct['reply'], 'speech' => $direct['speech'] ?? $direct['reply'],
                    'meta' => $direct['meta'], 'conversation_id' => $conv->id,
                ]);

                return;
            }
            if ($this->isHeavyTask($transcript)) {
                $event = \App\Pai\Perception\PaiEvent::create([
                    'source' => 'voice', 'topic' => 'console.request',
                    'payload' => ['message' => $transcript, 'conversation_id' => $conv->id],
                    'status' => \App\Pai\Perception\EventStatus::Received,
                ]);
                \App\Pai\Cognition\RouteCommandJob::dispatch($event->id);
                $ack = '好的，這個我需要大約一分鐘查資料、整理，請先別關掉，弄好我直接念給你。';
                $conv->addMessage('assistant', $ack, ['source' => 'voice', 'category' => 'task', 'event_id' => $event->id]);
                $emit('step', ['text' => '🧠 背景連續操作中…']);
                $emit('done', ['reply' => $ack, 'speech' => $ack, 'meta' => ['category' => 'task', 'background' => true], 'conversation_id' => $conv->id]);

                return;
            }

            // 一般路由：技能步驟即時推、閒聊類逐 token 推
            $steps = [];
            $onStep = function (string $t) use (&$steps, $emit) {
                $steps[] = $t;
                $emit('step', ['text' => $t]);
            };
            try {
                $r = $this->responder->route($conv, $transcript, $onStep);
                if ($r['stream']) {
                    $full = '';
                    $this->llm->stream($r['messages'], function (string $delta) use (&$full, $emit) {
                        $full .= $delta;
                        $emit('delta', ['text' => $delta]);
                    });
                    $reply = trim($full);
                    $meta = ['category' => 'chat'];
                } else {
                    $reply = (string) $r['reply'];
                    $meta = $r['meta'] ?? [];
                }
            } catch (Throwable $e) {
                \Illuminate\Support\Facades\Log::error('voice stream 失敗', ['err' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine(), 'trace' => $e->getTraceAsString()]);
                $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
                $meta = ['error' => true];
            }
            if ($reply === '') {
                $reply = '我沒有產生回覆，請再說一次。';
            }
            $conv->addMessage('assistant', $reply, array_merge($meta, ['source' => 'voice', 'trace' => $steps]));
            $this->maybeShowDoc($transcript, $reply);
            $emit('done', [
                'reply' => $reply, 'speech' => $this->speechClean($reply),
                'meta' => $meta, 'conversation_id' => $conv->id,
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /** 把回覆清成「適合朗讀」的乾淨口語：去除程式碼/路徑/網址/emoji/markdown，並精簡長度。 */
    private function speechClean(string $text): string
    {
        $t = $text;
        $t = preg_replace('/```.*?```/su', '', $t);                 // 程式碼區塊
        $t = preg_replace('/`[^`]*`/u', '', $t);                    // 行內 code
        $t = preg_replace('#https?://\S+#u', '網址', $t);            // 網址
        $t = preg_replace('#(?:sudo |/)[\w./@\-]+#u', '', $t);       // 指令/絕對路徑
        $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $t); // emoji
        // 去 markdown：標題#、粗體**、項目符號、引用、表格線
        $t = preg_replace('/^[ \t]*[#>\-*•・|]+[ \t]*/mu', '', $t);
        $t = str_replace(['**', '*', '＿', '`', '|'], '', $t);
        $t = preg_replace('/[（(][^）)]*detached[^）)]*[）)]/iu', '', $t);
        $t = preg_replace('/[ \t]*\R+[ \t]*/u', '，', $t);          // 換行→停頓
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = preg_replace('/，{2,}/u', '，', $t);
        $t = trim($t, " 。.·、,，\t\n");

        // 過長 → 只念前幾句，其餘請看畫面（避免長文 TTS 變怪、太久）
        if (mb_strlen($t) > 160) {
            $parts = preg_split('/(?<=[。！？!?])/u', $t, -1, PREG_SPLIT_NO_EMPTY);
            $acc = '';
            foreach ($parts as $p) {
                if (mb_strlen($acc.$p) > 150) {
                    break;
                }
                $acc .= $p;
            }
            $t = trim($acc) !== '' ? trim($acc).' 詳細內容已顯示在畫面上。' : mb_substr($t, 0, 150).'…';
        }

        return $t !== '' ? $t : $text;
    }

    /**
     * 直達指令：不經 LLM，直接把明確的語音命令對應到技能執行。
     * 目前支援「打開/開啟/啟動 <程式>」→ open-app。回 null 表示非直達指令、走一般 agentic。
     *
     * @return array{reply:string,meta:array,step?:string}|null
     */
    /**
     * 語音說「盯著畫面/發生X就叫我」→ 建立視覺守望任務（背景週期判讀）。
     * 即時投影/鏡頭開著就直接吃推送畫面（live:{sid}，5 秒一輪）；否則盯手機螢幕截圖。
     *
     * @return array{reply:string,speech:string,meta:array,step:string}
     */
    /**
     * 「幫我盯著鏡頭/前面/門口」但投影還沒開 → AI 自己開手機鏡頭（camera_vision 工具），
     * 等畫面推上來再建立 live 守望；守望結束時會自動把鏡頭關掉（autocam 標記）。
     */
    private function startCameraWatch(Conversation $conv, string $t, string $sid): array
    {
        if (preg_match('/(撞|障礙|障碍)/u', $t)) {
            return $this->startVisionWatch($conv, $t, $sid, false); // 秒級危險 → 裡面的前向警戒分支接手
        }
        $uid = (int) $conv->user_id;
        $node = \App\Pai\Watch\WatchTask::phoneNode($uid);
        $fail = function (string $why) use ($conv): array {
            $reply = "我想自己打開鏡頭來盯，但失敗了：{$why}。也可以在 App 語音頁手動按「鏡頭投影」再跟我說一次。";
            $conv->addMessage('assistant', $reply, ['source' => 'voice', 'skill' => 'watch']);

            return ['reply' => $reply, 'speech' => $this->speechClean($reply),
                'meta' => ['category' => 'skill', 'skill' => 'watch-screen', 'direct' => true], 'step' => '👀 啟動守望'];
        };
        if ($node === null) {
            return $fail('找不到在線的手機');
        }
        $r = \App\Pai\Mcp\ReverseBus::call($node, 'camera_vision', ['on' => true], 25);
        if (empty($r['ok']) || str_contains((string) ($r['text'] ?? ''), '沒有相機權限')) {
            return $fail((string) ($r['error'] ?? $r['text'] ?? '手機沒回應（App 可能是舊版）'));
        }
        // 以手機實際回報的 session 為準（保險）
        if (preg_match('/session:([A-Za-z0-9_-]+)/', (string) ($r['text'] ?? ''), $sm)) {
            $sid = $sm[1];
        }
        // 等第一張畫面推上來（每 2 秒推一張 → 最多等 8 秒；沒等到也先建守望，tick 有 3 次失敗緩衝）
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline && (string) \Illuminate\Support\Facades\Cache::get('vision:pending:'.$sid, '') === '') {
            usleep(500_000);
        }

        return $this->startVisionWatch($conv, $t, $sid, true, autoCam: true);
    }

    private function startVisionWatch(Conversation $conv, string $t, ?string $sid, bool $live, bool $autoCam = false): array
    {
        $uid = (int) $conv->user_id;
        $goal = str_replace(['頂著', '顶着'], '盯著', trim($t)); // STT 同音字還原，留完整句當守望目標

        // 「快撞到/障礙物」＝秒級物理危險 → 雲端 5 秒輪詢註定來不及，改開手機本地「前向警戒」（毫秒級）
        if (preg_match('/(撞|障礙|障碍)/u', $goal)) {
            $node = \App\Pai\Watch\WatchTask::phoneNode($uid);
            $r = $node !== null ? \App\Pai\Mcp\ReverseBus::call($node, 'collision_guard', ['on' => true], 25) : ['ok' => false, 'error' => '找不到在線手機'];
            $reply = ! empty($r['ok'])
                ? '「快撞到」這種秒級警示，雲端判讀來不及——我改用手機本地的「前向警戒」幫你盯：已開啟，請把鏡頭朝向前方，有東西逼近會立刻嗶聲警告。說「關閉前向警戒」可停止。'
                : '這種秒級警示要用手機本地的「前向警戒」才來得及，但開啟失敗：'.((string) ($r['error'] ?? $r['text'] ?? '手機沒回應')).'。請確認 App 已更新到最新版再說一次「開啟前向警戒」。';
            $conv->addMessage('assistant', $reply, ['source' => 'voice', 'skill' => 'collision-guard']);

            return ['reply' => $reply, 'speech' => $this->speechClean($reply),
                'meta' => ['category' => 'skill', 'skill' => 'collision-guard', 'direct' => true], 'step' => '👁 前向警戒'];
        }
        if (\App\Pai\Watch\WatchTask::where('user_id', $uid)->where('status', 'active')->count() >= 3) {
            $reply = '同時最多盯 3 個畫面，先說「取消守望」停掉一些再來。';
        } else {
            $interval = $live ? 5 : 20;
            $w = \App\Pai\Watch\WatchTask::create([
                'user_id' => $uid,
                'node' => \App\Pai\Watch\WatchTask::phoneNode($uid),
                'source' => ($live && $sid) ? 'live:'.$sid : 'screen',
                'goal' => $goal,
                'interval_sec' => $interval,
                'expires_at' => now()->addMinutes(30),
            ]);
            \App\Pai\Watch\WatchTickJob::dispatch($w->id, $w->issueTickToken());
            if ($autoCam) {
                \Illuminate\Support\Facades\Cache::put("watch:autocam:{$w->id}", 1, 7200); // 守望收尾時自動關鏡頭
            }
            $reply = '👀 好，'.($autoCam ? '我把手機鏡頭打開了，' : '')."我真的開始盯了（#{$w->id}）：{$goal}。每 {$interval} 秒看一次"
                .($live ? ($autoCam ? '鏡頭畫面（請把手機鏡頭朝向要盯的方向）' : '你投影上來的即時畫面') : '手機畫面')
                .'，最多 30 分鐘，看到就通知你＋念出來；說「取消守望」可停止。';
            if (preg_match('/(撞|危險|危险|跌倒|摔|安全|小偷|火|瓦斯)/u', $goal)) {
                $reply .= "\n⚠️ 老實說：我每一輪要好幾秒才判讀一次，秒級的碰撞/危險警示我來不及，"
                    .'請不要把行車安全交給我；適合等紅綠燈變化、東西煮好、有人出現這類幾十秒級的事。';
            }
        }
        $conv->addMessage('assistant', $reply, ['source' => 'voice', 'skill' => 'watch']);

        return ['reply' => $reply, 'speech' => $this->speechClean($reply),
            'meta' => ['category' => 'skill', 'skill' => 'watch-screen', 'direct' => true], 'step' => '👀 啟動守望'];
    }

    /**
     * 物品記憶：「記住護照放在這」→ 有鏡頭/照片就看圖精確描述位置，沒有就記口述位置，
     * 存進長期記憶（item-location）。之後問「護照放哪」由記憶注入直接答。
     *
     * @return array{reply:string,speech:string,meta:array,step:string}
     */
    private function rememberItemLocation(Conversation $conv, string $t, ?string $img): array
    {
        $uid = (int) $conv->user_id;
        // 抽物品名：「記住(護照)放在…」
        $item = '';
        if (preg_match('/(?:記住|记住|幫我記|帮我记)[，,、 ]?(.{1,14}?)(?:放在|收在|停在|擺在|摆在|放這|放这|在這|在这|的位置)/u', $t, $m)) {
            $item = trim(str_replace(['我的', '我把', '我'], '', $m[1]));
        }
        $desc = '';
        if ($img !== null) {
            // 看圖描述精確位置
            try {
                $desc = trim($this->llm->chat([
                    ['role' => 'system', 'content' => '你是視覺助理。使用者要記住物品的存放位置，請看照片用「一句話」精確描述這個位置（哪個空間/家具/抽屜/層架，旁邊有什麼參照物）。台灣正體中文，只輸出那一句話。'],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => '物品：'.($item ?: '（未說品名）').'。描述照片中的存放位置。'],
                        ['type' => 'image_url', 'image_url' => ['url' => $img]],
                    ]],
                ], ['max_tokens' => 200]));
            } catch (Throwable) {
            }
        }
        if ($desc === '' && preg_match('/(?:放在|收在|停在|擺在|摆在)([^，。,\.!？?]{1,30})/u', $t, $lm)) {
            $desc = trim($lm[1]); // 口述位置
        }
        if ($desc === '') {
            $reply = '要記在哪裡？把鏡頭對準位置再說一次，或直接說「記住護照放在書桌抽屜」。';
        } else {
            $when = now('Asia/Taipei')->format('Y-m-d H:i');
            app(\App\Pai\Memory\UserMemoryStore::class)->remember(
                $uid, '物品位置：'.($item ?: '未說品名的物品').' 放在 '.$desc.'（'.$when.' 記錄）', 'item-location'
            );
            $reply = '🧠 記住了：'.($item ?: '這個東西')." 放在 {$desc}。之後問我「".($item ?: '它').'放哪」就答得出來。';
        }
        $conv->addMessage('assistant', $reply, ['source' => 'voice', 'skill' => 'memory']);

        return ['reply' => $reply, 'speech' => $this->speechClean($reply),
            'meta' => ['category' => 'skill', 'skill' => 'memory', 'direct' => true], 'step' => '🧠 記物品位置'];
    }

    /** 帶圖回答（Gemma 4 看圖）：附近期對話脈絡 + 這張圖。 */
    private function visionReply(Conversation $conv, string $question, string $image, bool $live = false): string
    {
        $q = trim($question) !== '' ? $question : '請看這張圖片，用台灣正體中文說明重點。';
        // 即時投影/鏡頭：每次都是「當前畫面」，不帶歷史（否則模型會一直複述第一張看到的畫面）
        $history = $live ? [] : $conv->messages()->latest('id')->limit(6)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => mb_substr((string) $m->content, 0, 500)])->values()->all();
        $sys = $live
            ? '你是「由 Vito 開發的助理」，看得懂圖片。這是使用者「目前螢幕/鏡頭的即時畫面」。只根據這張當前畫面回答，用台灣正體（繁體）中文、簡短，禁簡體。'
                .'你只看得到這一張，沒有持續監看能力——絕對不可以承諾「我會幫你盯著/有狀況叫你」；'
                .'若使用者想要持續監看，請他說「幫我盯著（要等的狀況）」來啟動守望模式。'
            : '你是「由 Vito 開發的助理」，看得懂圖片。用台灣正體（繁體）中文回答，禁簡體。依對話脈絡針對這張圖回答或追問。';
        $messages = [
            ['role' => 'system', 'content' => $sys],
            ...$history,
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $q],
                ['type' => 'image_url', 'image_url' => ['url' => $image]],
            ]],
        ];
        try {
            $r = trim($this->llm->chat($messages, ['max_tokens' => 1024]));

            return $r !== '' ? $this->utf8($r) : '我看不太出來，換個角度或更清楚的照片再試。';
        } catch (Throwable $e) {
            return '看圖失敗：'.$e->getMessage();
        }
    }

    private ?int $turnOwnerId = null;   // 本回合對話擁有者（per-user 設定 + 裝置範圍用）
    private ?string $turnDeviceNode = null; // 本回合「當前裝置」（發指令的那台）

    private function directCommand(string $transcript, ?array $geo = null, ?Conversation $conv = null): ?array
    {
        $this->turnOwnerId = $conv?->user_id;
        $this->turnDeviceNode = $this->currentDevice($conv);
        $t = trim($transcript);

        // ── 待回答的提問（通勤/自動化 ask）→ 用語音回答「好/不用」即可決定 ──────────
        if ($conv && ($pq = $this->pendingVoiceAnswer($conv->user_id, $t)) !== null) {
            return $pq;
        }

        // ── 停止/取消進行中的處理序列 ──────────────────────────────────────────
        if ($conv && preg_match('/^(請|请)?\s*(停止|停下|停下來|停一下|別做了|别做了|不用了|不要做了|取消(處理|处理|執行|执行|任務|任务)?|別處理了|别处理了|停|cancel|stop)[。!！.\s]*$/iu', $t)) {
            \Illuminate\Support\Facades\Cache::put('pai:abort:'.$conv->id, true, 300);

            return ['reply' => '好，停止了。', 'speech' => '好的，停止了。',
                'meta' => ['category' => 'skill', 'skill' => 'control', 'direct' => true, 'action' => 'stop'], 'step' => '🛑 停止處理'];
        }

        // ── 情境模式：「睡覺模式/進入睡覺模式」→ 執行 Scene（真的有這個模式才攔，管理語句放行）──
        if ($conv && str_contains($t, '模式')
            && ! preg_match('/(建立|新增|幫我建|帮我建|刪除|删除|刪掉|删掉|列出|哪些|人格|persona|profile)/iu', $t)
            && ($scene = \App\Pai\Automation\Scene::match((int) $conv->user_id, $t)) !== null) {
            $msg = $scene->run($this->turnDeviceNode);

            return ['reply' => '🎬 '.$msg, 'speech' => $msg,
                'meta' => ['category' => 'skill', 'skill' => 'scene', 'direct' => true], 'step' => "🎬 {$scene->name}模式"];
        }

        // ── 切換人格 / 模式（Agent Profile）────────────────────────────────────
        if ($conv && preg_match('/(切換|切换|換成|换成|改用|切到|使用)\s*(.{1,20}?)\s*(人格|模式|persona|profile|角色)/iu', $t, $pm)) {
            $name = trim($pm[2]);
            $svc = app(\App\Pai\Agent\PersonaProfiles::class);
            $ok = $svc->switchTo($conv->user_id, $name);
            if ($ok !== null) {
                return ['reply' => "好，已切換到「{$ok}」人格。", 'speech' => "好的，已經切換到{$ok}人格。",
                    'meta' => ['category' => 'skill', 'skill' => 'persona', 'direct' => true, 'action' => 'switch'], 'step' => "🎭 切換人格：{$ok}"];
            }
            $names = collect($svc->all($conv->user_id))->pluck('name')->implode('、');
            return ['reply' => "找不到「{$name}」這個人格。目前有：{$names}", 'speech' => "找不到那個人格喔，目前有 {$names}。",
                'meta' => ['category' => 'skill', 'skill' => 'persona', 'direct' => true], 'step' => '🎭 人格切換失敗'];
        }

        // ── 切換語音音色 / 引擎（AI 調整聲音）──────────────────────────────────
        if ($conv && preg_match('/(語音|聲音|嗓音|音色|voice)/iu', $t) && preg_match('/(換|改|切換|切到|變成|用|設成)/u', $t)) {
            $uid = $conv->user_id;
            $speaker = null;
            $engine = null;
            $map = ['Vivian' => '/vivian|薇薇安|台灣女|台湾女|女聲|女生|女的/iu', 'Maple' => '/maple|楓|台灣男|台湾男|男聲|男生|男的/iu',
                'Luna' => '/luna|露娜/iu', 'Leo' => '/leo/iu', 'Kai' => '/kai|渾厚|浑厚/iu', 'Mia' => '/mia/iu', 'Aria' => '/aria/iu', 'Ryan' => '/ryan/iu'];
            foreach ($map as $sp => $re) {
                if (preg_match($re, $t)) {
                    $speaker = $sp;
                    break;
                }
            }
            if (preg_match('/f5/iu', $t)) {
                $engine = 'f5';
            } elseif (preg_match('/edge/iu', $t)) {
                $engine = 'edge';
            } elseif (preg_match('/minicpm|原生/iu', $t)) {
                $engine = 'minicpm';
            }
            if ($speaker !== null || $engine !== null) {
                if ($speaker !== null) {
                    $this->settings->set('voice.tts_speaker', $speaker, $uid);
                    if ($engine === null) {
                        $this->settings->set('voice.tts_engine', 'edge', $uid); // 選音色預設用 edge（多音色）
                    }
                }
                if ($engine !== null) {
                    $this->settings->set('voice.tts_engine', $engine, $uid);
                }
                $label = $speaker ?? $engine;

                return ['reply' => "好，已把聲音換成「{$label}」。", 'speech' => "好的，已經換成{$label}的聲音了。",
                    'meta' => ['category' => 'skill', 'skill' => 'voice', 'direct' => true, 'action' => 'switch_voice'], 'step' => "🔊 切換語音：{$label}"];
            }
        }

        // ── 鏡頭投影開關（直達）：「打開/關閉鏡頭投影」「開鏡頭給你看」→ AI 自己開手機鏡頭 ──
        if ($conv && preg_match('/(鏡頭|镜头)/u', $t)
            && preg_match('/(投影|給你看|给你看|讓你看|让你看|打開|打开|開啟|开启|關閉|关闭|關掉|关掉|停止|開|开|關|关)/u', $t)
            && ! preg_match('/(前向|防撞|碰撞|盯|守望|監看|监看)/u', $t)) {
            $camOff = (bool) preg_match('/(關閉|关闭|關掉|关掉|停止|取消|不用|收起|關了|关了)/u', $t);
            $node = $this->turnDeviceNode ?: \App\Pai\Mcp\ReverseBus::ownerPhoneNode((int) $conv->user_id);
            if (! $node) {
                return ['reply' => '找不到在線的手機節點，開不了鏡頭。', 'speech' => '找不到在線的手機，開不了鏡頭。',
                    'meta' => ['category' => 'skill', 'skill' => 'camera-vision', 'direct' => true], 'step' => '📷 鏡頭投影'];
            }
            $lens = preg_match('/(前鏡頭|前镜头|自拍)/u', $t) ? 'front' : 'back';
            $r = \App\Pai\Mcp\ReverseBus::call($node, 'camera_vision', ['on' => ! $camOff, 'lens' => $lens], 25);
            $msg = ! empty($r['ok'])
                ? preg_replace('/（session:[^）]*）/u', '', (string) ($r['text'] ?? '好了。'))
                : '操作失敗：'.(string) ($r['error'] ?? '手機沒回應（App 可能是舊版）');

            return ['reply' => $msg, 'speech' => $msg,
                'meta' => ['category' => 'skill', 'skill' => 'camera-vision', 'direct' => true], 'step' => '📷 鏡頭投影'];
        }

        // ── 前向警戒開關（直達，不經 LLM）：「開啟/關閉前向警戒」（含 STT 同音：警界/警介）──
        if ($conv && preg_match('/(前向|防撞|碰撞)\s*(警戒|警界|警介|偵測|侦测|模式)/u', $t)) {
            // 關/停動詞可在名詞前或後（「關閉前向警戒」「把防撞偵測關掉」都要通）
            $off = (bool) preg_match('/(關閉|关闭|關掉|关掉|停止|取消|停掉|不要|關了|关了)/u', $t);
            $node = $this->turnDeviceNode ?: \App\Pai\Mcp\ReverseBus::ownerPhoneNode((int) $conv->user_id);
            if (! $node) {
                return ['reply' => '找不到在線的手機節點，開不了前向警戒。', 'speech' => '找不到在線的手機，開不了前向警戒。',
                    'meta' => ['category' => 'skill', 'skill' => 'collision-guard', 'direct' => true], 'step' => '👁 前向警戒'];
            }
            $r = \App\Pai\Mcp\ReverseBus::call($node, 'collision_guard', ['on' => ! $off], 25);
            $msg = ! empty($r['ok']) ? (string) ($r['text'] ?? '好了。') : '操作失敗：'.(string) ($r['error'] ?? '手機沒回應');

            return ['reply' => $msg, 'speech' => $msg,
                'meta' => ['category' => 'skill', 'skill' => 'collision-guard', 'direct' => true], 'step' => '👁 前向警戒'];
        }

        // ── 會議模式：「開始記會議」→ 手機持續錄音轉寫；「結束會議」→ 摘要+待辦 ──────
        if ($conv && preg_match('/(開始|开始|幫我|帮我)(記|记|錄|录)?.{0,2}(會議|会议)|(會議|会议)(記錄|纪录|记录)?(開始|开始)/u', $t)
            && ! preg_match('/(結束|结束|停止|取消)/u', $t)) {
            $uid = (int) $conv->user_id;
            if (\App\Pai\Meeting\Meeting::activeFor($uid) !== null) {
                return ['reply' => '已經在記錄會議了，說「結束會議」就會整理摘要。', 'speech' => '已經在記錄會議了。',
                    'meta' => ['category' => 'skill', 'skill' => 'meeting', 'direct' => true], 'step' => '🎙️ 會議記錄'];
            }
            $node = $this->turnDeviceNode ?: \App\Pai\Mcp\ReverseBus::ownerPhoneNode($uid);
            if (! $node) {
                return ['reply' => '找不到在線的手機，開不了會議錄音。', 'speech' => '找不到在線的手機，開不了會議錄音。',
                    'meta' => ['category' => 'skill', 'skill' => 'meeting', 'direct' => true], 'step' => '🎙️ 會議記錄'];
            }
            $m = \App\Pai\Meeting\Meeting::create(['user_id' => $uid, 'status' => 'recording', 'started_at' => now()]);
            $r = \App\Pai\Mcp\ReverseBus::call($node, 'meeting_record', ['on' => true], 25);
            if (empty($r['ok']) || str_contains((string) ($r['text'] ?? ''), '沒有') || str_contains((string) ($r['text'] ?? ''), '使用中')) {
                $m->delete();
                $why = (string) ($r['text'] ?? $r['error'] ?? '手機沒回應（App 可能是舊版）');

                return ['reply' => "會議錄音開啟失敗：{$why}", 'speech' => "會議錄音開啟失敗：{$why}",
                    'meta' => ['category' => 'skill', 'skill' => 'meeting', 'direct' => true], 'step' => '🎙️ 會議記錄'];
            }

            return ['reply' => "🎙️ 會議記錄開始（#{$m->id}）：手機持續錄音、每 20 秒轉寫一段。結束時說「結束會議」，我會整理摘要＋決議＋待辦（有期限的直接排提醒）。",
                'speech' => '會議記錄開始了，結束時跟我說結束會議。',
                'meta' => ['category' => 'skill', 'skill' => 'meeting', 'direct' => true], 'step' => '🎙️ 會議記錄'];
        }
        if ($conv && preg_match('/(結束|结束|停止)(記|记|錄|录)?.{0,2}(會議|会议)|(會議|会议)(結束|结束)/u', $t)) {
            $uid = (int) $conv->user_id;
            $m = \App\Pai\Meeting\Meeting::activeFor($uid);
            if ($m === null) {
                return ['reply' => '目前沒有進行中的會議記錄。', 'speech' => '目前沒有在記錄會議。',
                    'meta' => ['category' => 'skill', 'skill' => 'meeting', 'direct' => true], 'step' => '🎙️ 會議記錄'];
            }
            if (($node = $this->turnDeviceNode ?: \App\Pai\Mcp\ReverseBus::ownerPhoneNode($uid)) !== null) {
                try {
                    \App\Pai\Mcp\ReverseBus::call($node, 'meeting_record', ['on' => false], 20);
                } catch (Throwable) {
                }
            }
            $m->status = 'summarizing';
            $m->ended_at = now();
            $m->save();
            \App\Pai\Meeting\MeetingSummaryJob::dispatch($m->id)->delay(now()->addSeconds(30)); // 等最後一段轉寫進來

            return ['reply' => '🛑 會議記錄結束，整理摘要中（約一分鐘），好了會推給你＋手機彈出完整記錄。',
                'speech' => '會議結束，我整理一下摘要和待辦，好了通知你。',
                'meta' => ['category' => 'skill', 'skill' => 'meeting', 'direct' => true], 'step' => '🎙️ 會議記錄'];
        }

        // ── 每日 Podcast：「播今天的 podcast/播客/晨間節目」→ 有今天的檔就直接播，沒有就現做 ──
        if ($conv && preg_match('/(podcast|播客|晨間節目|晨间节目|今日節目|今日节目)/iu', $t)
            && ! preg_match('/(關閉|关闭|取消|不要|停止)/u', $t)) {
            $uid = (int) $conv->user_id;
            $file = 'podcast/'.$uid.'-'.now('Asia/Taipei')->format('Ymd').'.mp3';
            if (is_file(storage_path('app/public/'.$file))) {
                $url = rtrim((string) config('app.url'), '/').'/storage/'.$file;
                if (($node = $this->turnDeviceNode ?: \App\Pai\Mcp\ReverseBus::ownerPhoneNode($uid)) !== null) {
                    try {
                        \App\Pai\Mcp\ReverseBus::fire($node, 'open_url', ['url' => $url]);
                    } catch (Throwable) {
                    }
                }

                return ['reply' => "🎙️ 播放今天的 Podcast：{$url}", 'speech' => '好，開始播今天的晨間節目。',
                    'meta' => ['category' => 'skill', 'skill' => 'podcast', 'direct' => true], 'step' => '🎙️ 播放 Podcast'];
            }
            \App\Pai\Schedule\PodcastJob::dispatch($uid);

            return ['reply' => '🎙️ 今天的還沒生成，我現在做（約一兩分鐘），好了會自動播放。',
                'speech' => '今天的節目還沒做好，我現在生成，大概一兩分鐘，好了會自動幫你播。',
                'meta' => ['category' => 'skill', 'skill' => 'podcast', 'direct' => true], 'step' => '🎙️ 生成 Podcast'];
        }

        // ── 物品記憶：「記住護照放在這」（有鏡頭畫面→看圖描述位置；口述→直接記）──────
        if ($conv && preg_match('/(記住|记住|幫我記|帮我记)/u', $t)
            && preg_match('/(放在|收在|停在|擺在|摆在|放這|放这|在這|在这|位置)/u', $t)
            && ! preg_match('/(忘記|忘记|取消)/u', $t)) {
            $itemImg = $conv->voice_sid
                ? (string) \Illuminate\Support\Facades\Cache::get('vision:pending:'.$conv->voice_sid, '')
                : '';

            return $this->rememberItemLocation($conv, $t, $itemImg !== '' ? $itemImg : null);
        }

        // ── 圖片對話：語音對話已掛了照片 → 這一輪帶圖回答（可多輪追問同一張）────────
        $sid = $conv?->voice_sid;
        if ($sid) {
            $pkey = 'vision:pending:'.$sid;
            if (preg_match('/(清除圖片|不看圖|不看了|看完了|移除圖片|忘掉.{0,3}圖)/u', $t)) {
                \Illuminate\Support\Facades\Cache::forget($pkey);

                return ['reply' => '好，已經放下那張圖片。', 'speech' => '好的，不看圖了。',
                    'meta' => ['category' => 'skill', 'skill' => 'vision', 'direct' => true], 'step' => '🖼 清除圖片'];
            }
            $img = \Illuminate\Support\Facades\Cache::get($pkey);
            // 「持續盯著/發生X就叫我」→ 建立真正的視覺守望（背景週期判讀），
            // 不能讓單次看圖回答把它吃掉、口頭答應卻沒人在盯（含 STT 同音：盯著→頂著）
            $watchIntent = (preg_match('/(盯著|盯着|頂著|顶着|盯住|盯緊|盯紧|守望|監看|监看|持續看|持续看)/u', $t)
                    || (preg_match('/(叫我|提醒我|通知我|告訴我|告诉我|警告我)/u', $t)
                        && preg_match('/(如果|要是|快要?|等到|出現|出现|變|变|就)/u', $t)))
                && ! preg_match('/(取消|停止|不用|別再|别再)/u', $t);
            // 要盯「鏡頭/前面/門口」但投影還沒開 → AI 自己開鏡頭，等畫面進來再建守望
            if ($watchIntent && $conv && (! is_string($img) || $img === '')
                && preg_match('/(鏡頭|镜头|前面|外面|周圍|周围|門口|门口)/u', $t)) {
                return $this->startCameraWatch($conv, $t, $sid);
            }
            if (is_string($img) && $img !== '') {
                $live = (bool) \Illuminate\Support\Facades\Cache::get('vision:live:'.$sid);
                if ($watchIntent && $conv) {
                    return $this->startVisionWatch($conv, $t, $sid, $live);
                }
                // 視覺意圖 vs 明確動作指令：避免「去公司/打開X/傳訊息」被殘留圖片綁架成看圖
                $visionIntent = (bool) preg_match('/(看到|看見|看见|看的|這是|这是|這張|这张|這個|这个|什麼|甚麼|什么|畫面|画面|螢幕|屏幕|圖片|图片|誰|谁|讀|读|介紹|介绍|描述|顏色|颜色|寫什麼|写什么|幾個|几个|哪一|是不是)/u', $t);
                $otherCmd = (bool) preg_match('/(導航|导航|帶我去|带我去|去[\x{4e00}-\x{9fff}]{1,10}|打開|打开|開啟|开启|啟動|启动|傳|传|訊息|讯息|播放|放歌|放音樂|取消|刪除|删除|記住|记住|記得|记得|提醒|排程|定時|打電話|打电话)/u', $t);
                if (($live || $visionIntent) && ! $otherCmd) {
                    $reply = $this->visionReply($conv, $t, $img, $live);
                    if (! $live) {
                        \Illuminate\Support\Facades\Cache::forget($pkey); // 一次性照片：答完自動放下，不再綁架後續對話
                    }
                    $conv->addMessage('assistant', $reply, ['source' => 'voice', 'skill' => 'vision']);

                    return ['reply' => $reply, 'speech' => $this->speechClean($reply),
                        'meta' => ['category' => 'skill', 'skill' => 'vision', 'direct' => true], 'step' => '👁 看圖回答'];
                }
                // 明確指令但有殘留的一次性圖片 → 順手放下，避免一直卡在看圖
                if (! $live && $otherCmd) {
                    \Illuminate\Support\Facades\Cache::forget($pkey);
                }
            }
        }

        // ── 定時任務（要在所有分支之前：句子帶時間+任務時先排程，不要立刻執行）────────
        // 查看：「我有哪些定時任務」「看一下排程」
        if (preg_match('/(定時|排程|預約|预约)/u', $t) && preg_match('/(哪些|列出|查看|清單|清单|列表|看一下|看看)/u', $t)) {
            $rows = \App\Pai\Schedule\ScheduledTask::where('status', 'pending')->orderBy('run_at')->limit(10)->get();
            $list = $rows->map(fn ($r) => '・'.$r->run_at->timezone('Asia/Taipei')->format('m/d H:i')
                .($r->recur === 'daily' ? '（每天）' : '')."：{$r->command}")->implode("\n");

            return [
                'reply' => $rows->isEmpty() ? '目前沒有排定的定時任務。' : "⏰ 排定中的任務：\n{$list}",
                'speech' => $rows->isEmpty() ? '目前沒有排定的定時任務。' : '目前排定 '.$rows->count().' 個任務：'.$rows->map(fn ($r) => $r->run_at->timezone('Asia/Taipei')->format('n月j日H點i分').'，'.$r->command)->implode('；'),
                'meta' => ['category' => 'skill', 'skill' => 'schedule', 'direct' => true, 'action' => 'list'],
                'step' => '⏰ 查看定時任務',
            ];
        }
        // 取消：「取消定時任務」「取消明天的排程」
        if (preg_match('/(取消|刪除|删除)/u', $t) && preg_match('/(定時|排程|預約|预约|提醒)/u', $t)) {
            $n = \App\Pai\Schedule\ScheduledTask::where('status', 'pending')->update(['status' => 'cancelled']);

            return [
                'reply' => $n > 0 ? "已取消 {$n} 個定時任務。" : '沒有可取消的定時任務。',
                'speech' => $n > 0 ? "好的，已取消 {$n} 個定時任務。" : '目前沒有排定中的任務。',
                'meta' => ['category' => 'skill', 'skill' => 'schedule', 'direct' => true, 'action' => 'cancel'],
                'step' => '⏰ 取消定時任務',
            ];
        }
        // 改時間：「把(剛剛的)提醒改成早上七點半」「那個定時任務改到明天九點」「提醒時間改成…」
        if (preg_match('/(改成|改到|改為|改为|改時間|改时间|重新排|重排|改一下時間)/u', $t)
            && preg_match('/(提醒|定時|定时|排程|那個|那个|剛剛|刚刚|任務|任务|時間|时间)/u', $t)) {
            $last = \App\Pai\Schedule\ScheduledTask::where('status', 'pending')->latest('id')->first();
            if ($last && ($sched = $this->parseSchedule($t.' 提醒'))) {
                [$runAt, $recur] = $sched;
                $last->update(['run_at' => $runAt->copy()->utc(), 'recur' => $recur ?? $last->recur]);

                return [
                    'reply' => "⏰ 已把「{$last->command}」改到 ".$runAt->format('n月j日 H:i').($recur === 'daily' ? '（每天）' : ''),
                    'speech' => '好的，已經改到'.$runAt->format('n月j日 H點i分').'了。',
                    'meta' => ['category' => 'skill', 'skill' => 'schedule', 'direct' => true, 'action' => 'reschedule'],
                    'step' => "⏰ 改時間 → {$runAt->format('n/j H:i')}",
                ];
            }
        }

        // 建立：「明天早上8:30幫我開導航到台中」「10分鐘後提醒我關火」「每天早上8點報天氣」
        // （提到「行事曆/日曆」→ 那是要建行事曆事件，不是定時自動執行任務，讓給下方的行事曆分支）
        if (! preg_match('/(行事曆|行事历|日曆|日历|行程表)/u', $t) && ($sched = $this->parseSchedule($t))) {
            [$runAt, $recur, $task] = $sched;
            \App\Pai\Schedule\ScheduledTask::create([
                'command' => $task, 'run_at' => $runAt->copy()->utc(), 'recur' => $recur,
                'conversation_id' => $conv?->id, 'status' => 'pending',
            ]);
            $when = ($recur === 'daily' ? '每天 ' : '').$runAt->format('n月j日 H:i');

            return [
                'reply' => "⏰ 已排定 {$when} 執行：「{$task}」",
                'speech' => '好的，已排定'.($recur === 'daily' ? '每天' : '').$runAt->format('n月j日 H點i分').'，到時我會自動幫你'.$task.'。',
                'meta' => ['category' => 'skill', 'skill' => 'schedule', 'direct' => true, 'action' => 'create'],
                'step' => "⏰ 排定 {$when}：{$task}",
            ];
        }

        // 在手機日曆建立事件：「在行事曆加 明天三點 開會」「幫我排明天下午兩點看牙醫到行事曆」
        if (preg_match('/(加到行事曆|加進行事曆|新增行程|新增事件|加.{0,3}行事曆|記到行事曆|排.{0,4}行事曆|建立.{0,2}事件|行事曆.{0,2}(新增|加))/u', $t)) {
            if ($sched = $this->parseSchedule($t)) {
                [$runAt, , $task] = $sched;
                $title = $task !== '' ? $task : '提醒';
                [$target, $targetLabel] = $this->targetGateway($t);
                $r = $this->reverseCall($target, 'add_calendar_event', [
                    'title' => $title, 'begin_ms' => $runAt->getTimestampMs(), 'end_ms' => 0,
                ]);
                if ($r !== null) {
                    [$res, $fail] = $r;

                    return [
                        'reply' => "📅 已在{$targetLabel}日曆預填「{$title}」（{$runAt->format('n/j H:i')}），請在手機按儲存。（{$res}）",
                        'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，已經在你手機日曆預填{$runAt->format('n月j日H點i分')}的{$title}，請按儲存。",
                        'meta' => ['category' => 'skill', 'skill' => 'calendar', 'direct' => true, 'action' => 'add_event', 'target' => $target],
                        'step' => "📅 建立行事曆事件：{$title}",
                    ];
                }
            }
        }

        // ── 晨間簡報 / 行事曆 / Gmail ───────────────────────────────────────────
        // 報今天概況 / 晨報
        if (preg_match('/(今天概況|今日概況|早報|晨報|晨間簡報|報一下今天|今天的簡報|today.{0,4}briefing)/iu', $t)) {
            $text = \App\Pai\Schedule\BriefingJob::build(
                app(\App\Pai\Settings\Settings::class), app(\App\Pai\Integrations\Calendar::class), app(\App\Pai\Integrations\Mailer::class));

            return ['reply' => $text, 'speech' => $this->speechClean($text),
                'meta' => ['category' => 'skill', 'skill' => 'briefing', 'direct' => true], 'step' => '☀️ 產生今日簡報'];
        }
        // 行事曆查詢：「今天/明天有什麼行程」「我的行程」
        if (preg_match('/(行程|行事曆|行事历|日程|今天.{0,3}(做什麼|有什麼事)|有什麼會|有什麼安排)/u', $t) && ! preg_match('/(排程|定時)/u', $t)) {
            $cal = app(\App\Pai\Integrations\Calendar::class);
            if (! $cal->configured()) {
                return ['reply' => '還沒設定行事曆。到中控台設定 calendar.ics_url（Google 行事曆的「秘密 iCal 網址」）就能看行程了。',
                    'speech' => '還沒設定行事曆，請先到中控台貼上 Google 行事曆的私人 iCal 網址。',
                    'meta' => ['category' => 'skill', 'skill' => 'calendar', 'direct' => true], 'step' => '📅 行事曆未設定'];
            }
            $tomorrow = (bool) preg_match('/(明天|明日|tomorrow)/iu', $t);
            $events = $tomorrow
                ? $cal->events(now('Asia/Taipei')->addDay()->startOfDay(), now('Asia/Taipei')->addDay()->endOfDay())
                : $cal->today();
            $day = $tomorrow ? '明天' : '今天';
            $list = collect($events)->map(fn ($e) => '・'.\App\Pai\Integrations\Calendar::line($e))->implode("\n");

            return ['reply' => $events ? "📅 {$day}的行程：\n{$list}" : "{$day}沒有行程。",
                'speech' => $events ? "{$day}有 ".count($events)." 個行程：".collect($events)->take(6)->map(fn ($e) => \App\Pai\Integrations\Calendar::line($e))->implode('；') : "{$day}沒有行程。",
                'meta' => ['category' => 'skill', 'skill' => 'calendar', 'direct' => true], 'step' => "📅 查{$day}行程"];
        }
        // 未讀信：「有幾封新信」「唸一下未讀信」
        if (preg_match('/(未讀.{0,2}信|新.{0,1}郵件|未讀郵件|有.{0,2}新信|幾封信|收信|gmail|電子郵件)/iu', $t) && ! preg_match('/(寄|傳|發|送)/u', $t)) {
            $mail = app(\App\Pai\Integrations\Mailer::class);
            $u = $mail->unread(8);
            if (! ($u['ok'] ?? false)) {
                return ['reply' => '讀信失敗或還沒設定 Gmail：'.($u['error'] ?? '未知').'（到中控台設 mail.address + mail.app_password）',
                    'speech' => '還沒設定 Gmail，請到中控台填入信箱與應用程式密碼。',
                    'meta' => ['category' => 'skill', 'skill' => 'mail', 'direct' => true], 'step' => '📧 讀信失敗'];
            }
            $cnt = $u['count'] ?? 0;
            $list = collect($u['items'])->map(fn ($it) => "・{$it['from']}：{$it['subject']}")->implode("\n");

            return ['reply' => $cnt > 0 ? "📧 未讀信 {$cnt} 封：\n{$list}" : '沒有未讀信。',
                'speech' => $cnt > 0 ? "有 {$cnt} 封未讀信，包括：".collect($u['items'])->take(5)->map(fn ($it) => "{$it['from']}寄來的{$it['subject']}")->implode('；') : '沒有未讀信。',
                'meta' => ['category' => 'skill', 'skill' => 'mail', 'direct' => true], 'step' => '📧 讀未讀信'];
        }

        // #5 回滾：「還原剛才的修改」「復原上一個變更」
        if (preg_match('/(還原|还原|復原|复原|回滾|回滚|撤銷|撤销|reset).{0,4}(剛才|刚才|上一|修改|變更|变更|設定|设定|檔案|文件)?/u', $t)
            && preg_match('/(還原|还原|復原|复原|回滾|回滚|撤銷|撤销)/u', $t)) {
            $res = app(\App\Pai\Skills\Builtin\RollbackSkill::class)->run([]);

            return [
                'reply' => $res, 'speech' => mb_strpos($res, '沒有') === 0 ? '沒有可以還原的修改。' : '好的，已經還原剛才的修改。',
                'meta' => ['category' => 'skill', 'skill' => 'rollback', 'direct' => true], 'step' => '↩️ 還原修改',
            ];
        }

        // 學會的技能查詢：「你學會了什麼」「你會做哪些事」
        if (preg_match('/(你?學會了什麼|你?学会了什么|學會哪些|学会哪些|你會做哪些|你会做哪些|有哪些技能|你的技能)/u', $t)) {
            $skills = \App\Pai\Skills\LearnedSkill::orderByDesc('uses')->limit(15)->get();

            return [
                'reply' => $skills->isEmpty()
                    ? '我還沒從任務中學會特別的做法——等你叫我完成幾個多步驟任務（像排行程、傳訊息給多人），我就會把成功的做法學起來，下次更快。'
                    : "🧠 我已經學會這些做法：\n".$skills->map(fn ($s) => "▶ {$s->name}（用過 {$s->uses} 次）：{$s->when_to_use}")->implode("\n"),
                'speech' => $skills->isEmpty()
                    ? '我還沒學會特別的做法，等你叫我完成幾個多步驟任務後，我就會把成功的方式記起來。'
                    : '我學會了 '.$skills->count().' 種做法，包括：'.$skills->take(6)->map(fn ($s) => $s->name)->implode('、'),
                'meta' => ['category' => 'skill', 'skill' => 'learned', 'direct' => true, 'action' => 'list_learned'],
                'step' => '🧠 查看學會的技能',
            ];
        }

        // ── 長期記憶（明確指令）────────────────────────────────────────────────
        $uid = $conv?->user_id;
        $memStore = app(\App\Pai\Memory\UserMemoryStore::class);
        // 記住：「記住我住汐止」「記得我喜歡吃魯肉飯」「幫我記一下…」
        if (preg_match('/^(請|请)?\s*(記住|记住|記得|记得|幫我記|帮我记|記一下|记一下|幫我記住|帮我记住)\s*(.{2,200})$/u', $t, $m)) {
            $fact = trim($m[3]);
            $ok = $memStore->remember($uid, $fact);

            return [
                'reply' => $ok ? "好，我記住了：「{$fact}」" : "這件事我已經記得了。",
                'speech' => $ok ? "好的，我記住了。" : "這個我已經記得了。",
                'meta' => ['category' => 'skill', 'skill' => 'memory', 'direct' => true, 'action' => 'remember'],
                'step' => "🧠 記住：{$fact}",
            ];
        }
        // 忘記：「忘記我住汐止」「不要記得…」
        if (preg_match('/^(請|请)?\s*(忘記|忘记|別記|别记|不要記|不要记|刪除記憶|删除记忆)\s*(.{2,100})$/u', $t, $m)) {
            $n = $memStore->forget($uid, trim($m[3]));

            return [
                'reply' => $n > 0 ? "好，已經忘記關於「".trim($m[3])."」的 {$n} 筆記憶。" : "我本來就沒有記這件事。",
                'speech' => $n > 0 ? "好的，我已經忘記了。" : "我本來就沒記這件事。",
                'meta' => ['category' => 'skill', 'skill' => 'memory', 'direct' => true, 'action' => 'forget'],
                'step' => '🧠 忘記記憶',
            ];
        }
        // 查看：「你記得我哪些事」「我有哪些記憶」
        if (preg_match('/(你記得|你记得|記得我|记得我|我的記憶|我的记忆|有哪些記憶|有哪些记忆|知道我哪些|關於我)/u', $t)) {
            $all = $memStore->all($uid);

            return [
                'reply' => $all->isEmpty() ? '我還沒記住關於你的長期資訊。你可以說「記住我住汐止」。'
                    : "🧠 我記得關於你的事：\n".$all->map(fn ($m) => '・'.$m->content)->implode("\n"),
                'speech' => $all->isEmpty() ? '我還沒記住關於你的事，你可以說記住我住哪裡之類的。'
                    : '我記得：'.$all->take(8)->map(fn ($m) => $m->content)->implode('；'),
                'meta' => ['category' => 'skill', 'skill' => 'memory', 'direct' => true, 'action' => 'list'],
                'step' => '🧠 查看長期記憶',
            ];
        }

        // 天氣查詢（open-meteo 免金鑰、真實預報）：「下週台中會下雨嗎」→ 直接回答降雨機率/氣溫
        if (preg_match('/(天氣|天气|下雨|降雨|會不會雨|会不会雨|氣溫|气温|溫度|温度)/u', $t)) {
            if ($w = $this->weatherAnswer($t, $geo)) {
                return $w;
            }
        }

        // 附近搜尋（用瀏覽器定位）：「附近有什麼好喝的飲料」→ 開 Google Maps 以使用者位置搜尋
        // 但「比較型」需求（最便宜/最高上限/評價最好/24小時/收費…要比較才知道）→ 地圖釘點搜尋做不到，
        // 交給 agentic（瀏覽器搜尋＋讀取＋比較）。這裡只處理「單純找某類地點」。
        $comparative = (bool) preg_match('/(最[高低貴便宜近好大小多少棒讚]|上限|評價|评价|cp值|c\/p|划算|24小時|24小时|收費|收费|計費|计费|費率|费率|比較|比较|哪.{0,3}(便宜|划算|好停|好))/iu', $t);
        // 要有「找某類東西」的意圖才算附近搜尋（光有「附近」不夠——避免把「我在台中火車站附近」這種地點敘述/訊息內容誤當搜尋）
        $nearbyIntent = (bool) preg_match('/(找|有什麼|有什么|有沒有|有没有|哪裡有|哪里有|哪邊有|哪边有|推薦|推荐|搜尋|搜寻|附近的)/u', $t)
            || preg_match('/(附近|周邊|周边).{0,8}(店|館|餐廳|餐厅|飯店|饭店|美食|小吃|咖啡|飲料|饮料|停車|停车|加油|超商|超市|藥局|药局|醫院|医院|景點|景点|公園|公园|廁所|厕所|銀行|银行|ATM|提款)/iu', $t);
        // 句子其實是「傳訊息/打電話/開App」等其他動作 → 不要當附近搜尋
        $otherAction = (bool) preg_match('/(傳|传|訊息|讯息|打電話|打电话|打給|打给|回覆|回复|告訴|告诉|跟.{1,8}說|给.{1,8}说|提醒|記住|记住)/u', $t);
        if (preg_match('/(附近|這附近|这附近|周邊|周边)/u', $t) && ! $comparative && $nearbyIntent && ! $otherAction) {
            $q = $this->extractNearbyQuery($t);
            if ($geo === null) {
                return [
                    'reply' => '我還不知道你的位置——請在語音頁允許「定位權限」後再問我一次。',
                    'speech' => '我還不知道你的位置，請在瀏覽器允許定位後，再問我一次。',
                    'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'nearby', 'no_geo' => true],
                    'step' => '📍 缺定位權限',
                ];
            }
            [$target, $targetLabel] = $this->targetGateway($t);
            $url = 'https://www.google.com/maps/search/'.rawurlencode($q).'/@'.$geo['lat'].','.$geo['lng'].',16z';
            $res = $this->runGui($target, 'open', 'chrome', $url);
            $fail = $this->guiFailed($res);

            return [
                'reply' => "好，已在{$targetLabel}打開附近「{$q}」的地圖搜尋（{$res}）",
                'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，幫你找了附近的{$q}，地圖已經打開了。",
                'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'nearby', 'target' => $target],
                'step' => "📍 附近搜尋：{$q}@{$targetLabel}",
            ];
        }

        // 系統狀態查詢（磁碟/記憶體/CPU…）→ 在目標節點跑真實指令拿真資料（不繞 LLM、不幻覺）
        if ($sys = $this->sysQuery($t)) {
            [$target, $targetLabel] = $this->targetGateway($t);
            $out = trim($this->runExec($target, $sys['cmd']));
            $speech = $this->summarizeSys($sys['key'], $out, $targetLabel);
            // 預設畫面也只顯示摘要；使用者明講要「詳細/完整/原始/明細」才附原始輸出
            $wantRaw = (bool) preg_match('/(詳細|详细|完整|原始|明細|明细|全部列|列出來|列出来)/u', $t);
            $reply = "【{$targetLabel}・{$sys['label']}】{$speech}";
            if ($wantRaw) {
                $reply .= "\n```\n".($out !== '' ? $out : '（沒有輸出）')."\n```";
            }

            return [
                'reply' => $reply,
                'speech' => $speech,
                'meta' => ['category' => 'skill', 'skill' => 'sysinfo', 'direct' => true, 'target' => $target],
                'step' => "📊 {$sys['label']}@{$targetLabel}",
            ];
        }

        // 停止/暫停播放（音樂/影片）→ 關掉目標節點瀏覽器目前分頁（必須在音樂分支之前判斷）
        $isStop = preg_match('/^(暫停|暂停|停止|停)[。!！]?$/u', $t)
            || preg_match('/(停止|暫停|暂停|停掉|關掉|关掉|不要|別|别)\s*(再)?\s*(播放|播|放)/u', $t)
            || preg_match('/(停止|暫停|暂停|停掉|關掉|关掉)\s*(音樂|音乐|歌|影片|视频|視頻|youtube)/iu', $t)
            || preg_match('/(音樂|音乐|歌|影片|视频|視頻)\s*(停|暫停|暂停|關|关)/u', $t);
        if ($isStop) {
            [$target, $targetLabel] = $this->targetGateway($t);
            // 手機節點 → 送系統媒體鍵暫停（對任何在播的音樂 App 都有效）
            if ($rv = $this->reverseCall($target, 'media_control', ['action' => 'pause'])) {
                [$out, $fail] = $rv;
            } else {
                $cmd = "osascript -e 'tell application \"Google Chrome\" to close active tab of front window' 2>/dev/null"
                    ." || playerctl -a pause 2>/dev/null || echo no-media";
                $out = trim($this->runExec($target, $cmd));
                $fail = str_contains($out, '未連線') || str_contains($out, '執行失敗');
            }

            return [
                'reply' => $fail ? "（{$targetLabel} 沒連上線，停不了播放）" : "好，已在{$targetLabel}停止播放。",
                'speech' => $fail ? $this->guiFailSpeech($targetLabel) : '好的，已經停止播放了。',
                'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'media_stop', 'target' => $target],
                'step' => "⏹ 停止播放@{$targetLabel}",
            ];
        }

        // 導航/路程：「導航到X」「開車去X要多久」「從A到B要多久」→ OSRM 實算距離/時間 + 開 Google Maps 路線
        $navDest = null;
        $navFrom = null;   // null = 用目前定位
        $navMode = 'driving';
        if (preg_match('/(從|从)\s*(.{1,20}?)\s*(開車|开车|騎車|骑车|走路|步行)?\s*(去|到|過去|过去)\s*(.{0,30}?)(要|需要|大概)?\s*(多久|多長時間|多长时间|多遠|多远|怎麼走|怎么走)/u', $t, $m)) {
            $navFrom = trim($m[2]);
            $navDest = trim($m[5]);
            $navMode = (str_contains($m[3], '走') || str_contains($m[3], '步')) ? 'walking' : 'driving';
            if ($navDest === '') {
                return [
                    'reply' => '要從「'.$navFrom.'」去哪裡呢？跟我說「從'.$navFrom.'到某地要多久」我就能直接算給你。',
                    'speech' => '要去哪裡呢？跟我說從'.$navFrom.'到哪裡，我就能直接算給你。',
                    'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'navigate', 'need_dest' => true],
                    'step' => '🧭 缺目的地',
                ];
            }
        } elseif (preg_match('/(導航|导航)\s*(到|去)?\s*(.{1,30}?)[。!！?？]?$/u', $t, $m)) {
            $navDest = trim($m[3]);
        } elseif (preg_match('/(開車|开车|騎車|骑车|走路|步行)(去|到)(.{1,30}?)(要|需要|大概)?\s*(多久|多長時間|多长时间|多遠|多远)/u', $t, $m)) {
            $navDest = trim($m[3]);
            $navMode = str_contains($m[1], '走') || str_contains($m[1], '步') ? 'walking' : 'driving';
        } elseif (preg_match('/(去|到)\s*(.{1,30}?)\s*(怎麼走|怎么走|怎麼去|怎么去)/u', $t, $m)) {
            $navDest = trim($m[2]);
        } elseif (preg_match('/^(.{1,30}?)\s*的?\s*(導航|导航|路線|路线)\s*$/u', $t, $m) && ! preg_match('/(怎麼|怎么|多久)/u', $t)) {
            // 「山河滷肉飯的導航」「台中導航」：目的地在「導航」之前
            $navDest = trim($m[1]);
        } elseif (preg_match('/(帶我去|带我去|帶我到|带我到)\s*(.{1,30}?)[。!！?？]?\s*$/u', $t, $m)) {
            $navDest = trim($m[2]);
        }
        if ($navDest !== null && $navDest !== '' && ! preg_match('/^(那|這|这|哪|過去|过去)/u', $navDest)) {
            // 指代型地點（公司/家/學校…）→ 先查使用者記憶換成真實地址；查不到才用字面
            $destQuery = $this->resolvePlaceFromMemory($navDest, $this->turnOwnerId) ?? $navDest;
            $fromQuery = $navFrom !== null && $navFrom !== '' ? ($this->resolvePlaceFromMemory($navFrom, $this->turnOwnerId) ?? $navFrom) : '';

            // 實算距離/時間（OSRM）
            $fromCoord = $fromQuery !== '' ? $this->geocodePlace($fromQuery)
                : ($geo !== null ? ['lat' => $geo['lat'], 'lng' => $geo['lng']] : null);
            $toCoord = $this->geocodePlace($destQuery);
            $est = ($fromCoord !== null && $toCoord !== null) ? $this->routeEstimate($fromCoord, $toCoord) : null;
            $fromLabel = $navFrom !== null && $navFrom !== '' ? $navFrom : '你的位置';

            // 導航優先開在「使用者自己的手機」原生地圖（開車時看手機）；沒有在線手機才退桌面
            $phoneNode = $this->ownerPhoneNode();
            if ($phoneNode !== null) {
                $args = ['destination' => $destQuery, 'mode' => $navMode];
                if ($fromQuery !== '') {
                    $args['origin'] = $fromQuery;
                } elseif ($geo !== null) {
                    $args['origin'] = $geo['lat'].','.$geo['lng'];
                }
                $rv = $this->reverseCall($phoneNode, 'maps_route', $args);
                $fail = $rv === null ? true : $rv[1];
                $where = '手機';
            } else {
                $params = ['api' => '1', 'destination' => $destQuery, 'travelmode' => $navMode];
                if ($fromQuery !== '') {
                    $params['origin'] = $fromQuery;
                } elseif ($geo !== null) {
                    $params['origin'] = $geo['lat'].','.$geo['lng'];
                }
                $url = 'https://www.google.com/maps/dir/?'.http_build_query($params);
                $fail = $this->guiFailed($this->runGui($this->targetGateway($t)[0], 'open', 'chrome', $url));
                $where = '電腦';
            }

            $estText = $est !== null ? "開車約 {$est[0]} 公里、大概 {$est[1]} 分鐘" : '';
            $speech = $fail
                ? '抱歉，導航沒能開起來，等等再試一次。'
                : ($estText !== ''
                    ? "好的，到{$navDest}{$estText}，路線開好了。"
                    : "好的，到{$navDest}的路線開好了。");

            return [
                'reply' => $fail ? '導航開啟失敗，請稍後再試。'
                    : "好，已在{$where}打開「{$fromLabel} → {$navDest}」的路線".($estText !== '' ? "（{$estText}）" : ''),
                'speech' => $speech,
                'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'navigate'],
                'step' => "🧭 導航：{$fromLabel}→{$navDest}".($estText !== '' ? "（{$est[0]}km/{$est[1]}分）" : ''),
            ];
        }

        // 音量 / 顯示器亮度（直接在目標節點調整；Mac 用 osascript，Linux 用 pactl/brightnessctl）
        // 也支援接續句：「再暗一點」「再大聲一點」「再小一點」（模糊時看上一個動作判斷音量或亮度）
        $avKeyword = (bool) preg_match('/(音量|聲音|声音|靜音|静音|\bvolume\b|\bmute\b|亮度|螢幕亮|屏幕亮|\bbrightness\b)/iu', $t);
        $avFollow = (bool) preg_match('/^(再|在)?\s*(調|调)?\s*(亮|暗|大聲|大声|小聲|小声|大|小)\s*(一點|一点|一些|點|点)?[。!！]?$/u', $t);
        if ($avKeyword || $avFollow) {
            $isBright = (bool) preg_match('/(亮度|螢幕亮|屏幕亮|brightness|調亮|调亮|調暗|调暗|亮一點|亮一点|暗一點|暗一点|變亮|变亮|變暗|变暗)/iu', $t)
                || ($avFollow && preg_match('/(亮|暗)/u', $t));
            // 「再大一點/再小一點」沒講是音量還亮度 → 看上一個語音動作的脈絡
            if ($avFollow && ! $isBright && ! preg_match('/(大聲|大声|小聲|小声)/u', $t) && $conv !== null) {
                $lastAction = $conv->messages()->where('role', 'assistant')->latest('id')->value('meta')['action'] ?? null;
                $isBright = $lastAction === 'brightness';
            }
            // 阿拉伯數字% / 百分之N / 調到N（N 可為中文數字，STT 常輸出「百分之五十」）
            $pct = null;
            if (preg_match('/(\d{1,3})\s*(%|％|趴)/u', $t, $m)) {
                $pct = (int) $m[1];
            } elseif (preg_match('/百分之\s*([0-9一二兩三四五六七八九十百]+)/u', $t, $m)) {
                $pct = $this->zhNum($m[1]);
            } elseif (preg_match('/(調到|调到|調整到|调整到|設成|设成|設到|设到)\s*([0-9一二兩三四五六七八九十百]+)/u', $t, $m)) {
                $pct = $this->zhNum($m[2]);
            }
            $pct = $pct !== null ? max(0, min(100, $pct)) : null;
            $up = (bool) preg_match('/(調高|调高|大聲|大声|提高|增加|調大|调大|大一點|大一点|高一點|高一点|調亮|调亮|亮一點|亮一点)/u', $t);
            $down = (bool) preg_match('/(調低|调低|小聲|小声|降低|減少|調小|调小|小一點|小一点|低一點|低一点|調暗|调暗|暗一點|暗一点)/u', $t);
            $unmute = (bool) preg_match('/(取消靜音|取消静音|解除靜音|解除静音|\bunmute\b)/iu', $t);
            $mute = ! $unmute && ! $isBright && (bool) preg_match('/(靜音|静音|\bmute\b)/iu', $t);
            [$cmd, $say] = $this->avCommand($isBright, $pct, $up, $down, $mute, $unmute);
            if ($cmd !== '') {
                [$target, $targetLabel] = $this->targetGateway($t);
                $out = trim($this->runExec($target, $cmd));
                $fail = str_contains($out, '未連線') || str_contains($out, '執行失敗');

                return [
                    'reply' => $fail ? "（{$targetLabel} 沒連上線，調不了）" : "好，已在{$targetLabel}{$say}。",
                    'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，{$say}了。",
                    'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => $isBright ? 'brightness' : 'volume', 'target' => $target],
                    'step' => ($isBright ? '🔆 ' : '🔊 ').$say."@{$targetLabel}",
                ];
            }
        }

        // 播放/搜尋音樂：有指定歌/歌手 → 瀏覽器開 YouTube 搜尋；沒指定 → 開 YouTube Music
        $mq = null;
        if (preg_match('/(播放|播|聽|听|放|搜尋|搜寻|搜索|找|\bplay\b).{0,12}(音樂|音乐|歌|\bmusic\b)/iu', $t)
            || preg_match('/(音樂|音乐|歌|\bmusic\b).{0,6}(播放|播|放|\bplay\b)/iu', $t)) {
            $mq = $this->extractMusicQuery($t);
        } elseif (preg_match('/^(請|请|麻煩|麻烦|幫我|帮我|我)?\s*(想|要)?(聽|听)\s*(.{1,30})$/u', $t, $m)) {
            $mq = $this->extractMusicQuery($m[4]);  // 「我想聽稻香」→ 稻香
        } elseif (preg_match('/^(請|请|麻煩|麻烦|幫我|帮我)?\s*(播放|播)\s*(.{1,30})$/u', $t, $m)) {
            $mq = $this->extractMusicQuery($m[3]);  // 「播放稻香」→ 稻香
        } elseif (preg_match('/^(請|请|麻煩|麻烦|幫我|帮我|我)?\s*(想|要)看\s*(.{1,30})$/u', $t, $m)) {
            // 「我想看你的名字」「我想看電影」→ YouTube 直接播（電影/影片這種泛稱就搜該詞）
            $mq = trim(preg_replace('/(的影片|影片|视频|電影|电影)$/u', '', $m[3]));
            $mq = $mq !== '' ? $mq : trim($m[3]);
        }
        if ($mq !== null) {
            [$target, $targetLabel] = $this->targetGateway($t);
            $q = $mq;
            // 手機節點 → 叫起原生音樂 App 直接播（不開 WebView 網頁）
            $rv = $this->reverseCall($target, $q !== '' ? 'play_music' : 'open_url',
                $q !== '' ? ['query' => $q] : ['url' => 'https://music.youtube.com/']);
            if ($rv !== null) {
                [$res, $fail] = $rv;
                $what = $q !== '' ? "「{$q}」" : '音樂';

                return [
                    'reply' => "好，已在{$targetLabel}播放{$what}（{$res}）",
                    'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，已經幫你播放{$what}了。",
                    'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'music', 'target' => $target],
                    'step' => "🎵 播放音樂".($q !== '' ? "：{$q}" : '')."@{$targetLabel}",
                ];
            }
            $playing = false;
            $url = 'https://music.youtube.com/';
            if ($q !== '') {
                // 直接播放：解析 YouTube 搜尋第一筆影片 → 開 watch 頁（進去就播）
                $vid = $this->youtubeFirstVideo($q);
                $playing = $vid !== '';
                $url = $playing
                    ? "https://www.youtube.com/watch?v={$vid}&autoplay=1"
                    : 'https://www.youtube.com/results?search_query='.rawurlencode($q);
            }
            $res = $this->runGui($target, 'open', 'chrome', $url);
            $fail = $this->guiFailed($res);
            $what = $q !== '' ? "「{$q}」" : '音樂';
            $verb = $playing ? '播放' : '打開';

            return [
                'reply' => "好，已在{$targetLabel}{$verb}{$what}（{$res}）",
                'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，已經幫你{$verb}{$what}了。",
                'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'music', 'target' => $target],
                'step' => "🎵 播放音樂".($q !== '' ? "：{$q}" : '')."@{$targetLabel}",
            ];
        }

        // 複雜需求（夾帶「開/關/搜尋」以外的其他動作）→ 交給 LLM agentic。
        // 注意：純連接詞（然後/並…）不算複雜——「打開瀏覽器然後搜尋新聞」仍走直達。
        if (preg_match('/(訂|购买|購買|寄|寫一|写一|發送|发送|傳給|传给|分析|總結|总结|翻譯|翻译|比較|比较|規劃|规划|整理|預訂|预订|提醒我|排程|安裝|安装|刪除|删除|排路線|排路线|路線|路线|景點|景点|完成後|完成后|然後.*[搜查找排看點點選比]|接著.*[搜查找排看])/u', $t)) {
            return null;
        }
        // 句子很長或含多個動作 → 多步任務，交 agentic（directCommand 只處理單一簡單指令）
        if (mb_strlen($t) > 24 && preg_match('/(完成|接著|接着|然後|然后|再|並|并|之後|之后)/u', $t)) {
            return null;
        }
        $hasOpen = (bool) preg_match('/(打開|打开|開啟|开启|啟動|启动|使用|叫出|呼叫|幫.{0,2}開|帮.{0,2}开|開一下|开一下|\bopen\b|\blaunch\b|\bstart\b|\buse\b)/iu', $t);
        $hasClose = (bool) preg_match('/(關閉|關掉|關起來|关闭|关掉|結束|结束|退出|\bclose\b|\bquit\b)/iu', $t);
        // 搜尋（含簡體：STT 常輸出簡體）
        $hasSearch = (bool) preg_match('/(搜尋|搜寻|搜索|查一下|查詢|查询|尋找|寻找|找一下|google一下|估狗|\bsearch\b|\bfind\b)/iu', $t);
        $hasBrowser = (bool) preg_match('/(瀏覽器|浏览器|chrome|google|browser|safari|firefox)/iu', $t);
        // 「打開視窗/窗口/網頁 + 搜尋」也視為要開瀏覽器
        $isWindow = (bool) preg_match('/(視窗|窗口|網頁|网页|window|分頁|分页)/iu', $t);
        // 「(瀏覽器或視窗) + 搜尋」即使沒明講「打開」也視為要開瀏覽器搜尋
        if (! $hasOpen && $hasSearch && ($hasBrowser || $isWindow)) {
            $hasOpen = true;
        }
        if (! $hasOpen && ! $hasClose) {
            return null;
        }

        $key = $this->appKey($t);
        // 要搜尋/開視窗但沒指明程式 → 用瀏覽器
        if ($key === null && $hasOpen && ($hasSearch || $isWindow)) {
            $key = 'chrome';
        }
        // 「打開 LINE / 開啟 Instagram / 啟動某 App」→ 直接 open_app（不繞 agentic、不先讀通知）
        // 但若句子是「打開 X 並/然後 做別的事」這種多意圖 → 不直達，交 agentic（它會開 App 再做後續）
        // 多意圖：開 App 之外還要做別的（傳訊息/查看/回覆…）→ 不直達，交 agentic
        $multiIntent = (bool) preg_match('/(並|并|然後|然后|接著|接着|再幫|再帮|，.*[看回傳發查]|,.*[看回傳發查]|順便|顺便|給.{1,12}說|给.{1,12}说|跟.{1,12}說|跟.{1,12}说|傳給|传给|傳訊息|传讯息|傳個|发个|發個|告訴|告诉|說我|说我|回覆|回复|看.{0,4}訊息|看.{0,4}消息|查看)/u', $t);
        if ($key === null && $hasOpen && ! $hasClose && ! $hasSearch && ! $multiIntent) {
            $appName = $this->extractAppName($t);
            if ($appName !== '') {
                [$target, $targetLabel] = $this->targetGateway($t);
                $r = $this->reverseCall($target, 'open_app', ['name' => $appName]);
                if ($r !== null) {
                    [$res, $fail] = $r;
                } else {
                    $res = $this->runGui($target, 'open', 'chrome', null); // 非手機節點退回桌面開法
                    $fail = $this->guiFailed($res);
                }

                return [
                    'reply' => "好，已在{$targetLabel}開啟「{$appName}」（{$res}）",
                    'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，已經幫你打開{$appName}了。",
                    'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'open_app', 'target' => $target],
                    'step' => "🚀 開啟 App：{$appName}@{$targetLabel}",
                ];
            }
        }
        if ($key === null) {
            return null;
        }
        $label = ['chrome' => '瀏覽器', 'firefox' => 'Firefox', 'terminal' => '終端機', 'calculator' => '計算機', 'files' => '檔案', 'settings' => '設定', 'editor' => '編輯器'][$key] ?? '程式';
        [$target, $targetLabel] = $this->targetGateway($t);

        if ($hasClose) {
            $res = $this->runGui($target, 'close', $key, null);
            $fail = $this->guiFailed($res);

            return [
                'reply' => "好，已在{$targetLabel}關閉「{$label}」（{$res}）",
                'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，已經幫你關閉{$label}了。",
                'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'close', 'target' => $target],
                'step' => "🛑 關閉：{$label}@{$targetLabel}",
            ];
        }

        // 開啟（可帶搜尋）
        $arg = null;
        $q = '';
        if ($hasSearch && $key === 'chrome') {
            $q = $this->extractQuery($t);
            if ($q !== '') {
                $arg = 'https://www.google.com/search?q='.rawurlencode($q);
            }
        }
        $res = $this->runGui($target, 'open', $key, $arg);
        $fail = $this->guiFailed($res);
        $disp = $q !== '' ? "「{$label}」並搜尋「{$q}」" : "「{$label}」";
        $spk = $fail
            ? $this->guiFailSpeech($targetLabel)
            : ($q !== '' ? "好的，已經幫你打開{$label}並搜尋{$q}了。" : "好的，已經幫你打開{$label}了。");

        return [
            'reply' => "好，已在{$targetLabel}開啟{$disp}（{$res}）",
            'speech' => $spk,
            'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'open', 'target' => $target],
            'step' => "🚀 開啟：{$label}@{$targetLabel}",
        ];
    }

    /** 是否為「重型多步任務」（需連續上網/比較/研究，數分鐘）→ 改背景跑，避免同步逾時。 */
    private function isHeavyTask(string $t): bool
    {
        // 研究/訂購等重型查詢
        if (preg_match('/(比價|比价|研究|調查|调查|分析|彙整|汇整|整理出|規劃|规划|行程|攻略|最便宜|最划算|哪間|哪家|住宿|機票|机票|飯店|饭店|報告|报告|訂票|订票|訂機票|订机票|訂房|订房|訂位|订位|訂飯店|预订|預訂|訂購|订购|購買|购买|報名|报名|掛號|挂号|租車|租车)/u', $t)) {
            return true;
        }
        // 手機 App 訊息自動化（開 LINE/傳訊息給某人…）：要多步螢幕操作，同步會 504，改背景做完再念回
        return (bool) preg_match('/((傳|傳送|发送|發送|私|回|幫我傳|幫我發).{0,6}(訊息|消息|line|賴|簡訊|短信)|傳訊息|傳 ?line|開.{0,4}line.{0,8}(傳|訊息)|跟.{1,8}說一聲|傳給.{1,12})/iu', $t);
    }

    /**
     * 系統狀態查詢 → 真實指令。回 ['cmd','label','key'] 或 null。
     * 故意把「磁鐵/磁盤」等 STT 常見誤聽也對應到磁碟，繞過辨識誤差。
     */
    private function sysQuery(string $t): ?array
    {
        $n = mb_strtolower($t);
        $isQuery = (bool) preg_match('/(查|看|顯示|显示|檢查|检查|多少|剩|用量|狀態|状态|還有|还有|有多|空間|空间|容量|多滿|多满)/u', $t);
        // 磁碟（含誤聽：磁鐵/磁盤/硬盤）
        if (preg_match('/(磁碟|磁盤|磁盘|磁鐵|磁铁|硬碟|硬盘|disk|儲存空間|存储空间|容量|空間|空间)/u', $t)) {
            return ['cmd' => 'df -h', 'label' => '磁碟用量', 'key' => 'disk'];
        }
        // 記憶體（Mac 無 free，退而用 vm_stat / top）
        if (preg_match('/(記憶體|记忆体|內存|内存|memory|\bram\b)/iu', $t)) {
            return ['cmd' => 'free -h 2>/dev/null || (top -l 1 2>/dev/null | grep -i phys) || vm_stat', 'label' => '記憶體', 'key' => 'mem'];
        }
        // CPU / 負載 / 開機多久
        if ($isQuery && preg_match('/(cpu|處理器|处理器|負載|负载|\bload\b|開機多久|开机多久|運行時間|运行时间|uptime)/iu', $t)) {
            return ['cmd' => 'uptime', 'label' => 'CPU 負載 / 運行時間', 'key' => 'cpu'];
        }
        // 概括系統狀態
        if (preg_match('/(系統狀態|系统状态|系統資訊|系统信息|機器狀態|机器状态)/u', $t)) {
            return ['cmd' => 'uname -a; echo; uptime; echo; df -h | head -6', 'label' => '系統狀態', 'key' => 'sys'];
        }

        return null;
    }

    /** 在目標節點（local=主節點 gateway，或某 MCP gateway）跑唯讀指令，回 stdout。 */
    private function runExec(string $target, string $cmd): string
    {
        if ($target === '__denied__') {
            return '（此帳號沒有操作主節點的權限）';
        }
        $name = ($target === '' || $target === 'local') ? 'gateway' : $target;
        $server = \App\Pai\Mcp\McpServer::where('name', $name)->where('enabled', true)->first();
        if (! $server) {
            return "（節點「{$name}」未連線）";
        }
        $r = app(\App\Pai\Mcp\McpClient::class)->callTool($server->url, $server->headers ?? [], 'exec', ['cmd' => $cmd]);

        return ($r['ok'] ?? false) ? (string) ($r['text'] ?? '') : '（執行失敗：'.($r['error'] ?? '未知').'）';
    }

    /** 把系統指令輸出整理成一句口語朗讀。 */
    private function summarizeSys(string $key, string $out, string $targetLabel): string
    {
        if (str_contains($out, '未連線') || str_contains($out, '執行失敗')) {
            return "抱歉，{$targetLabel} 目前沒連上線，查不到。";
        }
        if ($key === 'disk') {
            // 優先 macOS 的資料卷（/ 是 sealed 系統卷、永遠 ~3%），再退回根目錄 /
            // 注意只取「第一個」百分比 = 容量使用率（macOS 行尾還有 iused% 會誤抓成 0%）
            $lines = preg_split('/\R/', $out);
            foreach (['#\s/System/Volumes/Data$#', '#\s/$#'] as $mountPat) {
                foreach ($lines as $line) {
                    $line = rtrim($line);
                    if (preg_match($mountPat, $line) && preg_match('/(\d+)%/', $line, $m)) {
                        $avail = preg_match('/([\d.]+\s*[KMGT]i?)\s+\d+%/', $line, $a) ? $a[1] : '';

                        return "{$targetLabel} 的磁碟使用了 {$m[1]}%".($avail ? "，還有 {$avail} 可用" : '')."。";
                    }
                }
            }

            return "已查到 {$targetLabel} 的磁碟用量，需要明細可以再跟我說。";
        }
        if ($key === 'mem') {
            foreach (preg_split('/\R/', $out) as $line) {
                // Linux free -h：Mem: 總量 已用 可用…
                if (preg_match('/^Mem:\s+(\S+)\s+(\S+)/', trim($line), $m)) {
                    return "{$targetLabel} 的記憶體共 {$m[1]}，用了 {$m[2]}。";
                }
                // macOS top：PhysMem: 15G used (…), 1G unused
                if (stripos($line, 'PhysMem') !== false && preg_match('/(\S+)\s+used.*?(\S+)\s+unused/i', $line, $m)) {
                    return "{$targetLabel} 的記憶體用了 {$m[1]}，剩 {$m[2]} 可用。";
                }
            }
        }
        if ($key === 'cpu' && trim($out) !== '' && ! str_contains($out, '（')) {
            $line = trim(preg_split('/\R/', trim($out))[0]);
            if (preg_match('/load averages?:\s*([\d.]+)/i', $line, $m)) {
                $up = preg_match('/up\s+([^,]+)/i', $line, $u) ? '，已開機 '.trim($u[1]) : '';

                return "{$targetLabel} 的 CPU 負載 {$m[1]}{$up}。";
            }
        }

        return "已查到 {$targetLabel} 的".($key === 'mem' ? '記憶體' : ($key === 'cpu' ? 'CPU 負載' : '系統'))."狀態，需要明細可以再跟我說。";
    }

    /** 從「附近有什麼好喝的飲料」抽出搜尋詞（飲料）；抽不出預設「餐廳」。 */
    private function extractNearbyQuery(string $t): string
    {
        $q = $t;
        $q = preg_replace('/(請問|请问|幫我|帮我|麻煩|麻烦|找一下|找找|查一下|搜尋|搜寻|搜索|看一下|看一看|看看|瞧瞧|逛逛|介紹|介绍|想要|想吃|想喝|想找|帶我|带我|去)/u', '', $q);
        $q = preg_replace('/(我們|我们|我家|咱們|咱们|我)(這|这)?/u', '', $q);  // 「我附近…」的代名詞
        $q = preg_replace('/(這附近|这附近|附近的|附近|周邊|周边)/u', '', $q);
        $q = preg_replace('/(有什麼|有什么|有沒有|有没有|哪裡有|哪里有|哪邊有|哪边有|推薦|推荐|好喝的|好吃的|好玩的|不錯的|不错的)/u', '', $q);
        $q = trim(preg_replace('/[，。！？,.!?\s]+/u', ' ', $q));
        $q = preg_replace('/^(有沒有|有没有|有)/u', '', $q);
        $q = preg_replace('/[吧啊喔哦嘛呀啦囉咯呢了的嗎吗]+$/u', '', $q);
        // 殘渣是純語助/單動詞（看/看看/吃/喝/玩/逛…）不當搜尋詞，改用語意預設
        $filler = ['看', '看看', '吃', '喝', '玩', '逛', '有', '要', '想', '的'];
        if (mb_strlen($q) < 2 || in_array($q, $filler, true)) {
            return preg_match('/(好玩|景點|景点|逛|景色|風景|风景)/u', $t) ? '景點'
                : (preg_match('/(好喝|飲料|饮料|咖啡|手搖|手摇)/u', $t) ? '飲料店'
                : (preg_match('/(好吃|美食|吃|餐|飯|饭|麵|面|食|宵夜)/u', $t) ? '美食' : '餐廳'));
        }

        return $q;
    }

    /**
     * 解析「定時任務」語句：時間（明天早上8:30 / 10分鐘後 / 每天8點）+ 任務文字。
     * 回 [Carbon $runAt, ?string $recur, string $task]；不是定時語句回 null。
     */
    private function parseSchedule(string $t): ?array
    {
        $now = now('Asia/Taipei');
        $runAt = null;
        $recur = null;
        $matched = '';

        // 「N分鐘後 / N小時後 / 半小時後」
        if (preg_match('/(半|\d{1,3}|[一二兩三四五六七八九十]+)\s*個?\s*(分鐘|分钟|小時|小时)\s*(後|后|之後|之后)/u', $t, $m)) {
            $n = $m[1] === '半' ? 0.5 : ($this->zhNum($m[1]) ?? (ctype_digit($m[1]) ? (int) $m[1] : null));
            if ($n !== null) {
                $mins = str_contains($m[2], '分') ? (int) ceil($n) : (int) round($n * 60);
                if ($mins >= 1) {
                    $runAt = $now->copy()->addMinutes($mins);
                    $matched = $m[0];
                }
            }
        }

        // 「(每天|今天|明天|後天)(早上|下午…)H點M分 / H:MM」
        if ($runAt === null && preg_match(
            '/(每天|每日|今天|明天|後天|后天|大後天|大后天)?\s*(早上|上午|中午|下午|晚上|傍晚|凌晨|半夜)?\s*'
            .'(\d{1,2}|[一二兩三四五六七八九十]+)\s*[點点:：]\s*(半|\d{1,2})?\s*分?/u', $t, $m)) {
            $day = $m[1] ?? '';
            $ampm = $m[2] ?? '';
            // 沒有日期詞也沒有時段詞、且用的是冒號以外寫法不明確時，要求至少有「點」或日期/時段詞，避免誤觸（如「比例16:9」已被數字規則排除大半）
            $h = ctype_digit($m[3]) ? (int) $m[3] : ($this->zhNum($m[3]) ?? -1);
            if ($h >= 0 && $h <= 24 && ($day !== '' || $ampm !== '' || str_contains($m[0], '點') || str_contains($m[0], '点'))) {
                $min = ($m[4] ?? '') === '半' ? 30 : (int) ($m[4] ?? 0);
                if (in_array($ampm, ['下午', '晚上', '傍晚'], true) && $h < 12) {
                    $h += 12;
                }
                if ($ampm === '中午' && $h <= 2) {
                    $h += 12;
                }
                $runAt = $now->copy()->setTime($h % 24, $min, 0);
                if ($day === '每天' || $day === '每日') {
                    $recur = 'daily';
                    if ($runAt->lte($now)) {
                        $runAt->addDay();
                    }
                } elseif ($day === '明天') {
                    $runAt->addDay();
                } elseif ($day === '後天' || $day === '后天') {
                    $runAt->addDays(2);
                } elseif ($day === '大後天' || $day === '大后天') {
                    $runAt->addDays(3);
                } else { // 今天 / 未指定：已過就推到明天（未指定時）；明確說今天且已過 → 不當排程
                    if ($runAt->lte($now)) {
                        if ($day === '今天') {
                            return null;
                        }
                        $runAt->addDay();
                    }
                }
                $matched = $m[0];
            }
        }

        if ($runAt === null) {
            return null;
        }

        // 任務文字 = 原句去掉時間片段與贅詞；保留「提醒」語意
        $task = trim(str_replace($matched, '', $t));
        // 反覆清掉開頭的時間殘詞/語助/代名詞（明天把、幫我、我要…），直到穩定
        for ($i = 0; $i < 4; $i++) {
            $task = (string) preg_replace('/^(請|请|麻煩|麻烦|幫我|帮我|幫|帮|記得|记得|到時候|到时候|到時|到时|再|然後|然后|明天|明日|今天|後天|后天|大後天|大后天|每天|每日|把|我要|我想|想|要|順便|顺便|就)+/u', '', trim($task));
        }
        $task = (string) preg_replace('/(幫我|帮我)/u', '', $task);
        $task = trim($task, " ，。,．.、");
        if (mb_strlen($task) < 2) {
            return null; // 沒有實際任務（如純報時「明天八點」）不排程
        }
        if (preg_match('/提醒/u', $t) && ! str_contains($task, '提醒')) {
            $task = '提醒我：'.$task;
        }

        return [$runAt, $recur, $task];
    }

    /** 中文數字 → 整數（支援 0-100：五十、八十五、一百…）；解析不了回 null。 */
    private function zhNum(string $s): ?int
    {
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $d = ['零' => 0, '一' => 1, '二' => 2, '兩' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        if ($s === '百' || $s === '一百') {
            return 100;
        }
        if (preg_match('/^([一二兩三四五六七八九])?十([一二三四五六七八九])?$/u', $s, $m)) {
            return ($m[1] !== '' ? $d[$m[1]] : 1) * 10 + (($m[2] ?? '') !== '' ? $d[$m[2]] : 0);
        }

        return $d[$s] ?? null;
    }

    /** 組音量/亮度調整指令（跨平台：osascript ↘ pactl/brightnessctl）。回 [cmd, 口語描述]。 */
    private function avCommand(bool $isBright, ?int $pct, bool $up, bool $down, bool $mute, bool $unmute): array
    {
        if ($isBright) {
            if ($pct !== null) {
                $frac = $pct / 100;

                return ["brightness {$frac} 2>/dev/null || brightnessctl set {$pct}% 2>/dev/null || echo unsupported", "把亮度調到 {$pct}%"];
            }
            if ($up || $down) {
                $key = $up ? 144 : 145; // macOS 亮度快捷鍵 key code
                $bc = $up ? '+10%' : '10%-';

                return ["osascript -e 'tell application \"System Events\"' -e 'repeat 4 times' -e 'key code {$key}' -e 'end repeat' -e 'end tell' 2>/dev/null || brightnessctl set {$bc} 2>/dev/null || echo unsupported", $up ? '把亮度調高' : '把亮度調低'];
            }

            return ['', ''];
        }
        if ($mute) {
            return ["osascript -e 'set volume output muted true' 2>/dev/null || pactl set-sink-mute @DEFAULT_SINK@ 1 2>/dev/null", '靜音'];
        }
        if ($unmute) {
            return ["osascript -e 'set volume output muted false' 2>/dev/null || pactl set-sink-mute @DEFAULT_SINK@ 0 2>/dev/null", '取消靜音'];
        }
        if ($pct !== null) {
            return ["osascript -e 'set volume output volume {$pct}' 2>/dev/null || pactl set-sink-volume @DEFAULT_SINK@ {$pct}% 2>/dev/null", "把音量調到 {$pct}%"];
        }
        if ($up || $down) {
            $delta = $up ? '+ 10' : '- 10';
            $pa = $up ? '+10%' : '-10%';

            return ["osascript -e 'set volume output volume ((output volume of (get volume settings)) {$delta})' 2>/dev/null || pactl set-sink-volume @DEFAULT_SINK@ {$pa} 2>/dev/null", $up ? '把音量調大' : '把音量調小'];
        }

        return ['', ''];
    }

    /** 用 YouTube 搜尋頁解析第一個影片 ID（免 API key）；失敗回空字串。 */
    private function youtubeFirstVideo(string $q): string
    {
        try {
            $html = \Illuminate\Support\Facades\Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                    'Accept-Language' => 'zh-TW,zh;q=0.9',
                ])
                ->get('https://www.youtube.com/results', ['search_query' => $q])
                ->body();

            return preg_match('/"videoId":"([\w-]{11})"/', $html, $m) ? $m[1] : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** 從「播放周杰倫的歌」抽出歌名/歌手；抽不出（如「放點音樂」）回空字串。 */
    private function extractMusicQuery(string $t): string
    {
        $q = $t;
        $q = preg_replace('/(請|请|麻煩|麻烦|幫我|帮我|幫忙|帮忙|可以|可不可以|能不能|然後|然后|接著|接着)/u', '', $q);
        $q = preg_replace('/在.{1,10}(上面|上|那邊|那边)/u', '', $q);
        $q = preg_replace('/(播放|聽一下|听一下|聽|听|播|放點|放点|放一首|放些|放|搜尋|搜寻|搜索|找一下|找)/u', '', $q);
        $q = preg_replace('/(的歌曲|的歌|歌曲|的音樂|的音乐|音樂|音乐|\bmusic\b|\bplay\b|這首|这首|那首|一首|一些|一下)/iu', '', $q);
        // 「播放點音樂吧」→ 殘留「點/吧」等虛詞要清掉，否則被當歌名
        $q = preg_replace('/(來點|来点|一點|一点|點|点|些)/u', '', $q);
        $q = trim(preg_replace('/[，。！？,.!?\s]+/u', ' ', $q));
        $q = preg_replace('/[吧啊喔哦嘛呀啦囉咯呢了]+$/u', '', $q);

        return mb_strlen($q) >= 2 ? $q : '';
    }

    /** 口語句子 → GUI 白名單 key（chrome/firefox/terminal/calculator/files/settings/editor）或 null。 */
    /** 從「打開/開啟 X」抽出 App 名稱（去掉動詞、節點、語助詞）。回空字串＝抽不出。 */
    private function extractAppName(string $t): string
    {
        $s = $t;
        // 多意圖只取 App 名部分：在「並/和/然後/再/，」之前切斷（後續動作交 agentic）
        $s = (string) preg_replace('/(並|并|和|然後|然后|接著|接着|再|，|,|、).*$/u', '', $s);
        // 去掉「在某節點上/裡」（惰性，需方位詞收尾，避免吃掉後面的 App 名）
        $s = preg_replace('/在[\x{4e00}-\x{9fff}A-Za-z0-9_\-]{1,12}?[上裡裏]\s*的?\s*/u', '', $s);
        $s = preg_replace('/(請|请|麻煩|麻烦|幫我|帮我|幫|帮|我想|我要|想要|可以|能不能|順便|顺便|然後|然后)/u', '', $s);
        $s = preg_replace('/(打開|打开|開啟|开启|啟動|启动|叫出|呼叫|開一下|开一下|開|开|使用|\bopen\b|\blaunch\b|\bstart\b|\buse\b)/iu', '', $s);
        $s = preg_replace('/(這個|这个|那個|那个|app|應用程式|应用程序|應用|应用|程式|程序|軟體|软件|一下|的)/iu', '', $s);
        $s = trim((string) preg_replace('/[，。！？、,.!?\s]+/u', ' ', $s));
        $s = trim($s, " 　,.，。、");

        return mb_strlen($s) >= 1 && mb_strlen($s) <= 20 ? $s : '';
    }

    private function appKey(string $name): ?string
    {
        $n = mb_strtolower(trim($name));
        $keys = [
            'chrome' => 'chrome', 'google chrome' => 'chrome', 'google' => 'chrome', 'googlechrome' => 'chrome',
            '谷歌' => 'chrome', '瀏覽器' => 'chrome', '浏览器' => 'chrome', 'chromium' => 'chrome', 'safari' => 'chrome', 'edge' => 'chrome',
            'firefox' => 'firefox', '火狐' => 'firefox',
            'terminal' => 'terminal', '終端' => 'terminal', '終端機' => 'terminal', '终端' => 'terminal',
            'calculator' => 'calculator', '計算機' => 'calculator', '计算器' => 'calculator', '計算器' => 'calculator', '计算机' => 'calculator', '計算机' => 'calculator', '計算' => 'calculator', '计算' => 'calculator',
            'files' => 'files', '檔案' => 'files', '文件管理' => 'files', '檔案總管' => 'files', 'nautilus' => 'files',
            'settings' => 'settings', '設定' => 'settings', '设置' => 'settings', '控制台' => 'settings',
            'gedit' => 'editor', '記事本' => 'editor', '文字編輯' => 'editor', '編輯器' => 'editor',
        ];
        foreach ($keys as $k => $v) {
            if (str_contains($n, $k)) {
                return $v;
            }
        }

        return null;
    }

    /** 決定在哪個節點操作：句中指名 > 預設設定 > local。回傳 [target, 顯示名]。 */
    /** 記住「發指令的當前裝置」→ 之後該對話的操作預設跑這台（平板說話就跑平板，不跑手機）。 */
    private function rememberDevice(?Conversation $conv, ?string $node): void
    {
        if (! $conv) {
            return;
        }
        $node = preg_replace('/[^a-z0-9_-]/i', '-', (string) $node);
        if ($node !== '' && $node !== 'local' && $node !== 'node') {
            \Illuminate\Support\Facades\Cache::put("pai:device:{$conv->id}", $node, 7200);
        }
    }

    /** 這個對話「當前裝置」節點名（發指令的那台）；沒有回 null。 */
    private function currentDevice(?Conversation $conv): ?string
    {
        return $conv ? \Illuminate\Support\Facades\Cache::get("pai:device:{$conv->id}") : null;
    }

    /**
     * 若有「待回答的提問」（通勤要不要傳主管 / 自動化 ask），用語音的「好/不用」直接作答。
     * 命中回傳語音回應陣列；沒有待答問題或聽不出是/否 → 回 null（走正常流程）。
     */
    private function pendingVoiceAnswer(?int $uid, string $t): ?array
    {
        if ($uid === null) {
            return null;
        }
        $pq = \Illuminate\Support\Facades\Cache::get("voice:pendingq:{$uid}");
        if (! is_array($pq)) {
            return null;
        }
        // 意圖解析：可複合（如「發送並打開導航」＝通知＋導航）
        $no = (bool) preg_match('/(不用|不要|別|别|算了|沒事|没事|不需要|\bno\b)/iu', $t);
        $wantMap = (bool) preg_match('/(導航|导航|地圖|地图|帶我去|带我去|路線|路线|開地圖|开地图|出發|出发)/u', $t);
        $wantSend = (bool) preg_match('/(傳|传|發送|发送|通知|訊息|讯息|跟他說|跟他说|告訴|告诉|說一聲|说一声|傳給|传给)/u', $t);
        $yes = (bool) preg_match('/(好|是|對|对|可以|麻煩|麻烦|幫我|帮我|要|沒問題|没问题|\bok\b|\byes\b|請|请)/iu', $t);
        if (! $yes && ! $no && ! $wantMap && ! $wantSend) {
            return null; // 聽不出意圖 → 不攔截，照常處理
        }
        \Illuminate\Support\Facades\Cache::forget("voice:pendingq:{$uid}");
        $node = $this->turnDeviceNode;

        if (($pq['kind'] ?? '') === 'mail') {
            // 收件匣助理：「好，寄出」→ 寄回覆草稿；「不用」→ 放棄
            $msg = app(\App\Pai\Integrations\InboxAssistant::class)->decide($uid, ! $no);

            return ['reply' => $msg, 'speech' => $msg, 'meta' => ['category' => 'skill', 'skill' => 'mail', 'direct' => true], 'step' => '✉️ 回信決定'];
        }
        if (($pq['kind'] ?? '') === 'safety') {
            // 安全確認：「我沒事/還好」→ 解除；「需要幫忙/救命/受傷」→ 立刻求援
            $help = (bool) preg_match('/(救命|救我|需要|受傷|受伤|快叫|119|110|幫忙|帮忙|求援)/u', $t);
            $fine = ! $help && (bool) preg_match('/(沒事|没事|還好|还好|我很好|不用|安全|解除|好了|ok|okay)/iu', $t);
            if (! $help && ! $fine) {
                \Illuminate\Support\Facades\Cache::put("voice:pendingq:{$uid}", $pq, 900); // 聽不懂 → 保留待答

                return null;
            }
            $msg = app(\App\Pai\Safety\SafetyGuard::class)->resolve($uid, $fine);

            return ['reply' => $msg, 'speech' => $msg, 'meta' => ['category' => 'skill', 'skill' => 'safety', 'direct' => true], 'step' => '🚨 安全確認'];
        }
        if (($pq['kind'] ?? '') === 'commute') {
            if ($no && ! $wantSend && ! $wantMap) {
                \Illuminate\Support\Facades\Cache::forget("commute:pending:{$uid}");
                $msg = '好，這次不傳訊息給主管。';
            } else {
                $parts = [];
                // 預設（單純說「好」）＝傳給主管；明確只說導航則不傳
                if (! $no && ($wantSend || ! $wantMap)) {
                    $parts[] = app(\App\Pai\Commute\CommuteGuard::class)->sendToManager($uid, (string) $node);
                }
                if ($wantMap) {
                    $parts[] = app(\App\Pai\Commute\CommuteGuard::class)->openMap($uid);
                }
                $msg = implode('；', array_filter($parts)) ?: '好的。';
            }

            return ['reply' => $msg, 'speech' => $msg, 'meta' => ['category' => 'skill', 'skill' => 'commute', 'direct' => true], 'step' => '🚗 通勤回覆'];
        }
        if (($pq['kind'] ?? '') === 'event') {
            $eg = app(\App\Pai\Commute\EventGuard::class);
            $willLate = (int) ($pq['late'] ?? 0) > 0;
            if ($no && ! $wantSend && ! $wantMap) {
                $msg = '好，知道了。';
            } else {
                $parts = [];
                // 預設（單純說「好」）：會遲到→傳訊息給對方；準時→開導航。明確講就照講的（可複合）。
                $doSend = $wantSend || (! $wantMap && $willLate);
                $doMap = $wantMap || (! $wantSend && ! $willLate);
                if ($doSend) {
                    $parts[] = $eg->notifyAttendee($uid, (string) $node);
                }
                if ($doMap) {
                    $parts[] = $eg->openMap($uid, (string) $node);
                }
                $msg = implode('；', array_filter($parts)) ?: '好的。';
            }

            return ['reply' => $msg, 'speech' => $msg, 'meta' => ['category' => 'skill', 'skill' => 'event', 'direct' => true], 'step' => '🗓️ 行程回覆'];
        }
        if (($pq['kind'] ?? '') === 'automation') {
            $msg = app(\App\Pai\Automation\AutomationEngine::class)->decide($uid, (int) ($pq['autoId'] ?? 0), $no ? 'no' : 'yes', (string) $node);

            return ['reply' => $msg, 'speech' => $msg, 'meta' => ['category' => 'skill', 'skill' => 'automation', 'direct' => true], 'step' => '🤖 自動化回覆'];
        }

        return null;
    }

    /** 指代型地點（公司/家/學校…）→ 從使用者記憶換成真實地址；查不到回 null。 */
    private function resolvePlaceFromMemory(string $place, ?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        $p = trim($place);
        if (! preg_match('/(公司|家|學校|学校|老家|辦公室|办公室|宿舍|租屋|住處|住处|店裡|店里)/u', $p)) {
            return null;
        }
        $rows = \App\Pai\Memory\UserMemory::where('user_id', $userId)->where('content', 'like', '%'.$p.'%')->get();
        foreach ($rows as $r) {
            // 內容像地址（含 市/區/路/號…）→ 直接拿整段當查詢
            if (preg_match('/(市|縣|县|區|区|鄉|乡|鎮|镇|路|街|號|号|巷|弄|樓|楼|段)/u', (string) $r->content)) {
                return (string) $r->content;
            }
        }

        return null;
    }

    /** 該帳號自己擁有、目前在線的手機（反向節點）名稱；沒有回 null。 */
    private function ownerPhoneNode(): ?string
    {
        try {
            $owned = \App\Pai\Mcp\McpServer::where('user_id', $this->turnOwnerId)
                ->where('url', 'like', 'reverse://%')->pluck('name')->all();
            foreach (\App\Pai\Mcp\ReverseBus::onlineNodes() as $n) {
                if (in_array($n, $owned, true)) {
                    return $n;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function targetGateway(string $t): array
    {
        $low = mb_strtolower($t);
        // 租戶裝置範圍：非 admin 只認自己擁有/被授權的節點（admin → 全部）
        $owner = $this->turnOwnerId ? \App\Models\User::find($this->turnOwnerId) : null;
        $allowed = ($owner && ! $owner->isAdmin()) ? $owner->allowedDeviceNames() : null;
        $canLocal = ! $owner || $owner->canUseLocal();
        if (preg_match('/(主節點|主节点|伺服器|服务器|這台|这台|本機|本机|server)/u', $t)) {
            return $canLocal ? ['local', '主節點'] : ['__denied__', '主節點（無權限）'];
        }
        $devices = \App\Pai\Mcp\McpServer::where('enabled', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('name', $allowed))->get();
        // 句中提到某個已註冊 MCP 節點名稱 → 用它
        foreach ($devices as $s) {
            if ($s->name !== 'gateway' && str_contains($low, mb_strtolower($s->name))) {
                return [$s->name, $s->name];
            }
        }
        if (preg_match('/(我的mac|我的電腦|我的电脑|我的筆電|mac\b|macbook)/iu', $t)) {
            $mac = $devices->first(fn ($s) => str_contains(mb_strtolower($s->name), 'mac'));
            if ($mac) {
                return [$mac->name, $mac->name];
            }
        }
        // 預設節點 = 當前裝置（發指令的那台）優先；其次才是設定的預設節點
        $def = $this->turnDeviceNode !== null && $this->turnDeviceNode !== ''
            ? $this->turnDeviceNode
            : (string) $this->settings->get('voice.default_gateway', config('pai.voice.default_gateway', 'local'), $this->turnOwnerId);
        if ($def === '' || $def === 'local') {
            // 預設是主節點，但此帳號無主節點權限 → 改用它第一個可用裝置，沒有就拒絕
            if (! $canLocal) {
                $first = $devices->first(fn ($s) => $s->name !== 'gateway');

                return $first ? [$first->name, $first->name] : ['__denied__', '（無可操作的節點）'];
            }

            return ['local', '主節點'];
        }

        return [$def, $def];
    }

    /** 在指定節點開/關 GUI app。local→pai-gui-open；遠端→該 MCP gateway 的 open_app/exec。 */
    /** 目標若是反向（手機）節點 → 直接呼叫其工具，回 [結果文字, 是否失敗]；非反向節點回 null（走原本路徑）。 */
    private function reverseCall(string $target, string $tool, array $args): ?array
    {
        $server = \App\Pai\Mcp\McpServer::where('name', $target)->where('enabled', true)->first();
        if (! $server || ! str_starts_with((string) $server->url, 'reverse://')) {
            return null;
        }
        $r = app(\App\Pai\Mcp\McpClient::class)->callTool($server->url, $server->headers ?? [], $tool, $args);

        return [
            ($r['ok'] ?? false) ? (string) ($r['text'] ?? '已執行') : ('遠端執行失敗：'.($r['error'] ?? '未知')),
            ! ($r['ok'] ?? false),
        ];
    }

    private function runGui(string $target, string $action, string $key, ?string $arg): string
    {
        if ($target === '__denied__') {
            return '（此帳號沒有操作主節點的權限，請管理員授權，或改用你自己的裝置）';
        }
        if ($target === 'local') {
            $base = 'sudo -u '.escapeshellarg($this->guiUser()).' /usr/local/bin/pai-gui-open ';
            $cmd = $action === 'close'
                ? $base.'--close '.escapeshellarg($key)
                : $base.escapeshellarg($key).($arg ? ' '.escapeshellarg($arg) : '');
            $skill = app(\App\Pai\Skills\SkillRegistry::class)->get('open-app');

            return $skill ? $skill->run(['command' => $cmd]) : '找不到 open-app 技能';
        }
        // 遠端節點：走該 gateway 的 MCP
        $server = \App\Pai\Mcp\McpServer::where('name', $target)->where('enabled', true)->first();
        if (! $server) {
            return "找不到節點「{$target}」";
        }
        $client = app(\App\Pai\Mcp\McpClient::class);
        $isReverse = str_starts_with((string) $server->url, 'reverse://'); // 手機（Android）節點
        // 手機節點開「瀏覽器」→ 用內建受控瀏覽器 browser_navigate（手機常沒裝 Chrome app）
        if ($action !== 'close' && $isReverse && $key === 'chrome') {
            $url = $arg ?: 'https://www.google.com';
            // 地圖網址用原生 Google 地圖 App 開（WebView 渲染不出地圖）；其他網址走內建瀏覽器
            if (preg_match('#//(www\.)?google\.[^/]+/maps|//maps\.google\.|//maps\.app\.goo\.gl#i', $url)) {
                $r = $client->callTool($server->url, $server->headers ?? [], 'open_url', ['url' => $url]);
            } else {
                $r = $client->callTool($server->url, $server->headers ?? [], 'browser_navigate', ['url' => $url]);
            }
        } elseif ($action === 'close') {
            $procs = ['chrome' => 'chrom', 'firefox' => 'firefox', 'terminal' => 'erminal', 'calculator' => 'alculator', 'files' => 'inder', 'settings' => 'ettings', 'editor' => 'edit'];
            $r = $isReverse
                ? $client->callTool($server->url, $server->headers ?? [], 'browser_close', [])
                : $client->callTool($server->url, $server->headers ?? [], 'exec', ['cmd' => 'pkill -if '.escapeshellarg($procs[$key] ?? $key)]);
        } else {
            $a = ['name' => $key];
            if ($arg) {
                $a['url'] = $arg;   // 開瀏覽器並導到搜尋網址
            }
            $r = $client->callTool($server->url, $server->headers ?? [], 'open_app', $a);
        }

        return ($r['ok'] ?? false) ? (string) ($r['text'] ?? '已執行') : ('遠端執行失敗：'.($r['error'] ?? '未知'));
    }

    /** runGui 結果是否代表失敗（節點離線/找不到/遠端錯誤）。 */
    private function guiFailed(string $res): bool
    {
        return (bool) preg_match('/(找不到節點|遠端執行失敗|找不到 open-app|失敗|error|refused|not found)/iu', $res);
    }

    /** 節點離線時的朗讀提示。 */
    private function guiFailSpeech(string $targetLabel): string
    {
        return "抱歉，{$targetLabel} 目前沒有連上線，沒辦法在那台開啟。請先把該節點的 gateway 連線起來。";
    }

    /** 從「搜尋 X / 查一下 X」抽出查詢字串。 */
    private function extractQuery(string $t): string
    {
        // 取搜尋動詞之後的全部當查詢（保留「的新聞」「資料」等，因為它們常是查詢的一部分）
        if (preg_match('/(?:搜尋|搜寻|搜索|查一下|查詢|查询|尋找|寻找|找一下|google一下|估狗|search|find)\s*(.+)$/iu', $t, $m)) {
            $q = trim($m[1]);
            $q = preg_replace('/^(一下|並|并|和|然後|然后|再|去|的)\s*/u', '', $q);
            $q = rtrim($q, " 。.!！?？、,，");

            return trim($q);
        }

        return '';
    }

    /** 由口語句子推出友善的 app 名稱（給朗讀用）。 */
    private function appLabel(string $transcript): string
    {
        $n = mb_strtolower($transcript);
        $labels = [
            'chrome' => 'Chrome', '谷歌' => 'Chrome', '瀏覽器' => '瀏覽器', '浏览器' => '瀏覽器', 'chromium' => 'Chrome',
            'safari' => 'Chrome', 'edge' => 'Chrome', 'firefox' => 'Firefox', '火狐' => 'Firefox',
            '終端' => '終端機', '终端' => '終端機', 'terminal' => '終端機',
            '計算機' => '計算機', '计算器' => '計算機', 'calculator' => '計算機',
            '檔案' => '檔案', 'files' => '檔案', '設定' => '設定', 'settings' => '設定',
            '記事本' => '記事本', '編輯器' => '編輯器',
        ];
        foreach ($labels as $k => $v) {
            if (str_contains($n, $k)) {
                return $v;
            }
        }

        return '程式';
    }

    /** 主節點圖形 session 的使用者（可由 config 覆寫）。 */
    private function guiUser(): string
    {
        return (string) (config('pai.voice.gui_user') ?: 'intellitrust');
    }

    /** 用 conversation_id 找既有對話；找不到（如電話來電）則用 session 綁定，最後退回為第一個使用者開新對話。 */
    private function resolveConversation(?int $id, ?string $session): Conversation
    {
        if ($id && ($conv = Conversation::find($id))) {
            return $conv;
        }

        $userId = User::orderBy('id')->value('id') ?? 1;

        if ($session) {
            $existing = Conversation::where('voice_sid', $session)->latest('id')->first();
            if ($existing) {
                return $existing;
            }

            return Conversation::create([
                'user_id' => $userId,
                'voice_sid' => $session,
                'title' => '語音對話',
            ]);
        }

        return Conversation::create(['user_id' => $userId, 'title' => '語音對話']);
    }
}
