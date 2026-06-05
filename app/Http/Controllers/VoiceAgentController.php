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
            'geo' => ['nullable', 'array'],
            'geo.lat' => ['required_with:geo', 'numeric'],
            'geo.lng' => ['required_with:geo', 'numeric'],
        ]);

        $transcript = trim($data['transcript']);
        if ($transcript === '') {
            return response()->json(['reply' => '', 'steps' => [], 'conversation_id' => $data['conversation_id'] ?? null]);
        }

        $conv = $this->resolveConversation($data['conversation_id'] ?? null, $data['session'] ?? null);
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
                'steps' => [$direct['step'] ?? '⚡ 直接執行'],
                'meta' => $direct['meta'],
                'conversation_id' => $conv->id,
            ]);
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
            $ack = '好的，這需要連續查資料、整理，我在背景幫你處理，完成後會通知你並出現在對話裡。';
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

        return response()->json([
            'reply' => $reply,
            'speech' => $this->speechClean($reply), // 朗讀用：去掉指令/路徑/網址/emoji，避免 TTS 念出怪聲
            'steps' => array_map(fn ($s) => $this->utf8($s), $steps),
            'meta' => $meta,
            'conversation_id' => $conv->id,
        ]);
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

    /**
     * SSE 串流版：邊生成邊回（voice_server 收到一句念一句，不用等全部跑完）。
     * 事件：step（執行步驟）/ delta（回覆文字片段）/ done（完整結果）。
     */
    public function stream(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $secret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Voice-Secret')), 401);

        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'session' => ['nullable', 'string', 'max:128'],
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
            $geo = $data['geo'] ?? null;
            $geoPlace = $geo ? $this->reverseGeocode((float) $geo['lat'], (float) $geo['lng']) : '';
            $conv->addMessage('user', $transcript, array_filter([
                'source' => 'voice', 'geo' => $geo, 'geo_place' => $geoPlace !== '' ? $geoPlace : null,
            ]));

            // 直達指令／重型背景任務：結果立即一次回（本來就快）
            if ($direct = $this->directCommand($transcript, $geo, $conv)) {
                $conv->addMessage('assistant', $direct['reply'], array_merge($direct['meta'], ['source' => 'voice']));
                $emit('step', ['text' => $direct['step'] ?? '⚡ 直接執行']);
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
                $ack = '好的，這需要連續查資料、整理，我在背景幫你處理，完成後會通知你並出現在對話裡。';
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
                $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
                $meta = ['error' => true];
            }
            if ($reply === '') {
                $reply = '我沒有產生回覆，請再說一次。';
            }
            $conv->addMessage('assistant', $reply, array_merge($meta, ['source' => 'voice', 'trace' => $steps]));
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
    private function directCommand(string $transcript, ?array $geo = null, ?Conversation $conv = null): ?array
    {
        $t = trim($transcript);

        // 附近搜尋（用瀏覽器定位）：「附近有什麼好喝的飲料」→ 開 Google Maps 以使用者位置搜尋
        if (preg_match('/(附近|這附近|这附近|周邊|周边)/u', $t)) {
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
            $cmd = "osascript -e 'tell application \"Google Chrome\" to close active tab of front window' 2>/dev/null"
                ." || playerctl -a pause 2>/dev/null || echo no-media";
            $out = trim($this->runExec($target, $cmd));
            $fail = str_contains($out, '未連線') || str_contains($out, '執行失敗');

            return [
                'reply' => $fail ? "（{$targetLabel} 沒連上線，停不了播放）" : "好，已在{$targetLabel}停止播放。",
                'speech' => $fail ? $this->guiFailSpeech($targetLabel) : '好的，已經停止播放了。',
                'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'media_stop', 'target' => $target],
                'step' => "⏹ 停止播放@{$targetLabel}",
            ];
        }

        // 導航/路程：「導航到X」「開車去X要多久」「去X怎麼走」→ 開 Google Maps 路線（起點=目前定位）
        $navDest = null;
        $navMode = 'driving';
        if (preg_match('/(導航|导航)\s*(到|去)?\s*(.{1,30}?)[。!！?？]?$/u', $t, $m)) {
            $navDest = trim($m[3]);
        } elseif (preg_match('/(開車|开车|騎車|骑车|走路|步行)(去|到)(.{1,30}?)(要|需要|大概)?\s*(多久|多長時間|多长时间|多遠|多远)/u', $t, $m)) {
            $navDest = trim($m[3]);
            $navMode = str_contains($m[1], '走') || str_contains($m[1], '步') ? 'walking' : 'driving';
        } elseif (preg_match('/(去|到)\s*(.{1,30}?)\s*(怎麼走|怎么走|怎麼去|怎么去)/u', $t, $m)) {
            $navDest = trim($m[2]);
        }
        if ($navDest !== null && $navDest !== '' && ! preg_match('/^(那|這|这|哪|過去|过去)/u', $navDest)) {
            [$target, $targetLabel] = $this->targetGateway($t);
            $params = ['api' => '1', 'destination' => $navDest, 'travelmode' => $navMode];
            if ($geo !== null) {
                $params['origin'] = $geo['lat'].','.$geo['lng'];
            }
            $url = 'https://www.google.com/maps/dir/?'.http_build_query($params);
            $res = $this->runGui($target, 'open', 'chrome', $url);
            $fail = $this->guiFailed($res);

            return [
                'reply' => "好，已在{$targetLabel}打開到「{$navDest}」的路線（{$res}）",
                'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，到{$navDest}的路線已經打開了，預估時間看地圖上的標示。",
                'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'navigate', 'target' => $target],
                'step' => "🧭 導航：{$navDest}@{$targetLabel}",
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
        if (preg_match('/(訂|购买|購買|寄|寫一|写一|發送|发送|傳給|传给|分析|總結|总结|翻譯|翻译|比較|比较|規劃|规划|整理|預訂|预订|提醒我|排程|安裝|安装|刪除|删除)/u', $t)) {
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
        return (bool) preg_match('/(比價|比价|研究|調查|调查|分析|彙整|汇整|整理出|規劃|规划|行程|攻略|最便宜|最划算|哪間|哪家|住宿|機票|机票|飯店|饭店|報告|报告)/u', $t);
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
        $q = preg_replace('/(請問|请问|幫我|帮我|麻煩|麻烦|找一下|找找|查一下|搜尋|搜寻|搜索)/u', '', $q);
        $q = preg_replace('/(這附近|这附近|附近的|附近|周邊|周边)/u', '', $q);
        $q = preg_replace('/(有什麼|有什么|有沒有|有没有|哪裡有|哪里有|哪邊有|哪边有|推薦|推荐|好喝的|好吃的|好玩的|不錯的|不错的)/u', '', $q);
        $q = trim(preg_replace('/[，。！？,.!?\s]+/u', ' ', $q));
        $q = preg_replace('/^(有沒有|有没有|有)/u', '', $q);
        $q = preg_replace('/[吧啊喔哦嘛呀啦囉咯呢了的嗎吗]+$/u', '', $q);
        if (mb_strlen($q) < 1) {
            // 抽不出關鍵詞 → 依語氣給合理預設
            return preg_match('/(好玩|景點|景点|逛)/u', $t) ? '景點'
                : (preg_match('/(好喝|飲料|饮料|咖啡)/u', $t) ? '飲料店' : '餐廳');
        }

        return $q;
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
    private function targetGateway(string $t): array
    {
        $low = mb_strtolower($t);
        if (preg_match('/(主節點|主节点|伺服器|服务器|這台|这台|本機|本机|server)/u', $t)) {
            return ['local', '主節點'];
        }
        // 句中提到某個已註冊 MCP 節點名稱 → 用它
        foreach (\App\Pai\Mcp\McpServer::where('enabled', true)->get() as $s) {
            if ($s->name !== 'gateway' && str_contains($low, mb_strtolower($s->name))) {
                return [$s->name, $s->name];
            }
        }
        if (preg_match('/(我的mac|我的電腦|我的电脑|我的筆電|mac\b|macbook)/iu', $t)) {
            $mac = \App\Pai\Mcp\McpServer::where('enabled', true)->where('name', 'like', '%mac%')->first();
            if ($mac) {
                return [$mac->name, $mac->name];
            }
        }
        $def = (string) $this->settings->get('voice.default_gateway', config('pai.voice.default_gateway', 'local'));

        return $def === '' || $def === 'local' ? ['local', '主節點'] : [$def, $def];
    }

    /** 在指定節點開/關 GUI app。local→pai-gui-open；遠端→該 MCP gateway 的 open_app/exec。 */
    private function runGui(string $target, string $action, string $key, ?string $arg): string
    {
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
        if ($action === 'close') {
            $procs = ['chrome' => 'chrom', 'firefox' => 'firefox', 'terminal' => 'erminal', 'calculator' => 'alculator', 'files' => 'inder', 'settings' => 'ettings', 'editor' => 'edit'];
            $r = $client->callTool($server->url, $server->headers ?? [], 'exec', ['cmd' => 'pkill -if '.escapeshellarg($procs[$key] ?? $key)]);
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
