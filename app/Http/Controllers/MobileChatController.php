<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 手機/原生端的訊息對話 API（非串流，secret 認證）。
 * 與 web 主控台共用同一批 Conversation（單一使用者：手機與網頁看到的是同一串對話）。
 * 認證：登入 session（web）或 X-Voice-Secret / X-Register-Secret（手機 gateway），對齊 VisionController。
 */
class MobileChatController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    /** 對話清單：id / 標題 / 最後一則摘要 / 時間。 */
    public function list(Request $request): JsonResponse
    {
        if (! $this->authed($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $owner = $this->ownerId();
        $convs = Conversation::where('user_id', $owner)
            ->latest('id')->limit(60)->get();

        return response()->json([
            'conversations' => $convs->map(function (Conversation $c) {
                $last = $c->messages()->latest('id')->first();

                return [
                    'id' => $c->id,
                    'title' => $c->title ?: '新對話',
                    'preview' => $last ? Str::limit(preg_replace('/\s+/', ' ', (string) $last->content), 48) : '',
                    'role' => $last?->role,
                    'at' => optional($c->updated_at)->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    /** 取單一對話的訊息歷史。 */
    public function history(Request $request): JsonResponse
    {
        if (! $this->authed($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $request->validate(['conversation_id' => ['required', 'integer']]);
        $conv = Conversation::where('user_id', $this->ownerId())->find($data['conversation_id']);
        if (! $conv) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'conversation_id' => $conv->id,
            'title' => $conv->title,
            'messages' => $conv->messages()->get()->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => (string) $m->content,
                'image' => $m->meta['image'] ?? null,   // 使用者發的圖（data URI）
                'at' => optional($m->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    /** 開新對話。 */
    public function new(Request $request): JsonResponse
    {
        if (! $this->authed($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $conv = Conversation::create(['user_id' => $this->ownerId()]);

        return response()->json(['conversation_id' => $conv->id]);
    }

    /**
     * 發送訊息（文字，或附一張圖片）。同步取得 AI 回覆後回傳。
     * body: { conversation_id?, message?, image?(data URI/base64) }
     */
    public function send(Request $request, ChatResponder $responder): JsonResponse
    {
        if (! $this->authed($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['nullable', 'string', 'max:4000'],
            'image' => ['nullable', 'string'],
        ]);
        $text = trim((string) ($data['message'] ?? ''));
        $image = $this->normalizeImage((string) ($data['image'] ?? ''));
        if ($text === '' && $image === '') {
            return response()->json(['error' => 'empty'], 422);
        }

        // 斜線指令：/new 開新對話、/clear 清空目前對話
        $slash = strtolower(ltrim($text, '/ '));
        if ($image === '' && in_array($slash, ['new', 'start'], true)) {
            $conv = Conversation::create(['user_id' => $this->ownerId()]);

            return response()->json(['conversation_id' => $conv->id, 'reply' => '', 'meta' => ['action' => 'new']]);
        }
        if ($image === '' && in_array($slash, ['clear', 'reset'], true)) {
            $conv = $this->resolveConv($data['conversation_id'] ?? null);
            $conv->messages()->delete();
            $conv->update(['title' => null, 'summary' => null, 'compacted_through_id' => null]);

            return response()->json(['conversation_id' => $conv->id, 'reply' => '', 'meta' => ['action' => 'clear']]);
        }

        $conv = $this->resolveConv($data['conversation_id'] ?? null);
        if ($conv->title === null) {
            $conv->update(['title' => Str::limit($text !== '' ? $text : '圖片訊息', 30)]);
        }

        // 使用者訊息（圖片存進 meta.image 供歷史顯示）
        $userMeta = $image !== '' ? ['source' => 'mobile', 'image' => $image] : ['source' => 'mobile'];
        $conv->addMessage('user', $text !== '' ? $text : '（圖片）', $userMeta);

        // 有圖 → 走多模態看圖；純文字 → 一般 respond
        if ($image !== '') {
            $reply = $responder->visionReply($conv, $text, $image);
            $meta = ['category' => 'chat', 'source' => 'vision'];
        } else {
            $r = $responder->respond($conv, $text);
            $reply = $r['reply'];
            $meta = $r['meta'];
        }
        $conv->addMessage('assistant', $reply, $meta);
        if ($text !== '') {
            \App\Pai\Memory\ExtractMemoryJob::dispatch($text, $reply, $conv->user_id); // 自動抽取長期記憶
        }

        return response()->json([
            'conversation_id' => $conv->id,
            'reply' => $reply,
            'meta' => $meta,
        ]);
    }

    /**
     * SSE 即時串流版（純文字）：逐步回報執行步驟(step) + 逐字回覆(delta) + 完成(done)。
     * Android 逐行消費。圖片仍走 send（多模態）。
     */
    public function stream(Request $request, ChatResponder $responder): StreamedResponse
    {
        $authed = $this->authed($request);
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        return response()->stream(function () use ($authed, $data, $responder) {
            @set_time_limit(0);
            ignore_user_abort(true);
            $emit = function (string $event, array $payload) {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };
            if (! $authed) {
                $emit('error', ['text' => 'unauthorized']);

                return;
            }
            $text = trim($data['message']);
            $conv = $this->resolveConv($data['conversation_id'] ?? null);
            if ($conv->title === null) {
                $conv->update(['title' => Str::limit($text, 30)]);
            }
            $conv->addMessage('user', $text, ['source' => 'mobile']);
            $emit('start', ['conversation_id' => $conv->id]);

            $trace = [];
            $onStep = function (string $t) use ($emit, &$trace) { $trace[] = $t; $emit('step', ['text' => $t]); };
            $onDelta = function (string $d) use ($emit) { $emit('delta', ['text' => $d]); };

            try {
                $category = $responder->category($conv, $text);
                if ($category === 'skill'
                    && empty(($r = $responder->skills()->handle($conv, $text, $onStep, $onDelta))['meta']['no_skill'])) {
                    $reply = $r['reply'];
                    $meta = $r['meta'];
                    if (empty($meta['streamed'])) {
                        foreach (mb_str_split($reply, 8) as $c) {
                            $emit('delta', ['text' => $c]);
                            usleep(6000);
                        }
                    }
                } else {
                    // 閒聊 / 無對應技能 → 取得回覆後分塊輸出（呈現串流感）
                    $rr = $responder->respond($conv, $text);
                    $reply = $rr['reply'] ?: '（沒有產生回覆）';
                    $meta = $rr['meta'] ?? ['category' => 'chat'];
                    foreach (mb_str_split($reply, 8) as $c) {
                        $emit('delta', ['text' => $c]);
                        usleep(6000);
                    }
                }
                $conv->addMessage('assistant', $reply, array_merge($meta, ['trace' => $trace]));
                \App\Pai\Memory\ExtractMemoryJob::dispatch($text, $reply, $conv->user_id); // 自動抽取長期記憶
                $emit('done', ['conversation_id' => $conv->id, 'reply' => $reply]);
            } catch (Throwable $e) {
                $conv->addMessage('assistant', '抱歉，這次處理失敗了：'.$e->getMessage(), ['error' => true]);
                $emit('error', ['text' => $e->getMessage()]);
                $emit('done', ['conversation_id' => $conv->id]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /** 終止這個對話進行中的回覆 / 背景任務（對齊 web 的 /chat/stop）。 */
    public function stop(Request $request): JsonResponse
    {
        if (! $this->authed($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $id = (int) $request->input('conversation_id');
        if ($id > 0) {
            Cache::put("pai:chat:abort:{$id}", true, 120);
            Cache::put("pai:abort:{$id}", true, 120);
        }

        return response()->json(['ok' => true]);
    }

    private function resolveConv(?int $id): Conversation
    {
        $owner = $this->ownerId();
        if ($id) {
            $conv = Conversation::where('user_id', $owner)->find($id);
            if ($conv) {
                return $conv;
            }
        }

        return Conversation::create(['user_id' => $owner]);
    }

    /**
     * 對話擁有者：web 登入用 session 使用者；手機用 device token 解析出綁定帳號；
     * 都沒有則退回主 admin（向後相容單一使用者部署）。
     */
    private function ownerId(): ?int
    {
        $req = request();
        if ($req?->user()) {
            return $req->user()->id;
        }
        $owner = GatewayController::ownerFromRequest($req);

        return $owner?->id ?? User::where('role', 'admin')->orderBy('id')->min('id') ?? User::query()->min('id');
    }

    private function authed(Request $request): bool
    {
        $secret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));

        // 登入 session / 語音密鑰 / gateway 憑證（共用 secret 或 per-device token 都接受）
        return $request->user() !== null
            || ($secret !== '' && hash_equals($secret, (string) $request->header('X-Voice-Secret')))
            || GatewayController::ownerFromRequest($request) !== null;
    }

    /** 統一成 data URI（沿用 VisionController 規則）。 */
    private function normalizeImage(string $img): string
    {
        $img = trim($img);
        if ($img === '') {
            return '';
        }
        if (str_starts_with($img, 'data:image/')) {
            return $img;
        }

        return 'data:image/jpeg;base64,'.preg_replace('/\s+/', '', $img);
    }
}
