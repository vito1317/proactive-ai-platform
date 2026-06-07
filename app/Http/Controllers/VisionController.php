<?php

namespace App\Http\Controllers;

use App\Pai\Chat\Conversation;
use App\Pai\Cognition\LlmClient;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * 視覺端點：收一張圖片（照片 / 螢幕畫面）+ 問題 → Gemma 4 看圖回答。
 * 給 web（登入 session）與手機（X-Voice-Secret）共用。
 */
class VisionController extends Controller
{
    public function __construct(private readonly LlmClient $llm, private readonly Settings $settings) {}

    public function analyze(Request $request): JsonResponse
    {
        // 驗證：登入 session（web）或語音共用密鑰（手機/gateway）
        $secret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));
        $regSecret = GatewayController::registerSecret();
        $authed = $request->user() !== null
            || ($secret !== '' && hash_equals($secret, (string) $request->header('X-Voice-Secret')))
            || ($regSecret !== '' && hash_equals($regSecret, (string) $request->header('X-Register-Secret')));
        if (! $authed) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'image' => ['nullable', 'string'],          // data URI 或 base64（追問時可省略，沿用上一張）
            'prompt' => ['nullable', 'string', 'max:1000'],
            'conversation_id' => ['nullable', 'integer'],
            'session' => ['nullable', 'string', 'max:128'],
            'live' => ['nullable', 'boolean'],          // 即時畫面模式 → 回答更簡短
        ]);

        $conv = $this->resolveConv($data['conversation_id'] ?? null, $data['session'] ?? null);
        $cacheKey = $conv ? "vision:img:{$conv->id}" : null;

        // 圖片：本次有帶就用，沒帶就沿用對話裡上一張（多輪追問同一張圖）
        $image = $this->normalizeImage((string) ($data['image'] ?? ''));
        if ($image === '' && $cacheKey) {
            $image = (string) \Illuminate\Support\Facades\Cache::get($cacheKey, '');
        }
        if ($image === '') {
            return response()->json(['error' => '沒有圖片（請先拍照/上傳一張）'], 422);
        }
        if ($cacheKey) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $image, 3600); // 圖片留 1 小時供追問
        }

        $prompt = trim((string) ($data['prompt'] ?? '')) ?: '請看這張圖片，用台灣正體中文描述你看到什麼、有什麼重點。';
        $live = (bool) ($data['live'] ?? false);
        $conv?->addMessage('user', $prompt, ['source' => 'vision']);

        $sys = '你是「由 Vito 開發的助理」，看得懂圖片。用台灣正體（繁體）中文回答，禁簡體。'
            .($live ? '這是使用者畫面的即時截圖，請簡短（一兩句）說明畫面重點或回答問題。' : '看圖回答使用者，簡潔實用。可依對話脈絡針對同一張圖追問。');
        // 帶近期對話脈絡（多輪追問）；圖片只附在這一輪的 user turn
        $history = [];
        if ($conv) {
            $history = $conv->messages()->where('id', '<', $conv->messages()->max('id'))
                ->latest('id')->limit(6)->get()->reverse()
                ->map(fn ($m) => ['role' => $m->role, 'content' => mb_substr((string) $m->content, 0, 500)])->values()->all();
        }
        $messages = [
            ['role' => 'system', 'content' => $sys],
            ...$history,
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $image]],
            ]],
        ];

        try {
            $reply = trim($this->llm->chat($messages, ['max_tokens' => $live ? 256 : 1024]));
        } catch (Throwable $e) {
            return response()->json(['error' => '看圖失敗：'.$e->getMessage()], 500);
        }
        if ($reply === '') {
            $reply = '我看不太出來，換個角度或更清楚的照片再試一次。';
        }
        $conv?->addMessage('assistant', $reply, ['source' => 'vision']);

        return response()->json([
            'reply' => $reply,
            'conversation_id' => $conv?->id,
        ]);
    }

    /** 手機把照片掛到「語音對話」session → 之後用語音追問都會帶這張圖（直到說「不看了」或 15 分鐘）。 */
    public function attach(Request $request): JsonResponse
    {
        $secret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));
        $authed = $request->user() !== null
            || ($secret !== '' && hash_equals($secret, (string) $request->header('X-Voice-Secret')))
            || hash_equals(GatewayController::registerSecret(), (string) $request->header('X-Register-Secret'));
        if (! $authed) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $request->validate([
            'image' => ['required', 'string'],
            'session' => ['required', 'string', 'max:128'],
        ]);
        $image = $this->normalizeImage($data['image']);
        if ($image === '') {
            return response()->json(['error' => '圖片格式不正確'], 422);
        }
        \Illuminate\Support\Facades\Cache::put('vision:pending:'.$data['session'], $image, 900);

        return response()->json(['ok' => true, 'message' => '照片已附上，直接用語音問就會看著它回答']);
    }

    /** 統一成 data URI（llama-server vision 接受 data:image/...;base64,）。 */
    private function normalizeImage(string $img): string
    {
        $img = trim($img);
        if (str_starts_with($img, 'data:image/')) {
            return $img;
        }
        // 純 base64 → 補上 data URI 前綴
        $clean = preg_replace('/\s+/', '', $img);
        if ($clean !== null && preg_match('/^[A-Za-z0-9+\/=]+$/', $clean) && strlen($clean) > 100) {
            return 'data:image/jpeg;base64,'.$clean;
        }

        return '';
    }

    private function resolveConv(?int $id, ?string $session): ?Conversation
    {
        try {
            if ($id && ($c = Conversation::find($id))) {
                return $c;
            }
            if ($session && ($c = Conversation::where('voice_sid', $session)->latest('id')->first())) {
                return $c;
            }
            $uid = \App\Models\User::orderBy('id')->value('id') ?? 1;

            return Conversation::create(['user_id' => $uid, 'voice_sid' => $session, 'title' => '看圖對話']);
        } catch (Throwable) {
            return null;
        }
    }
}
