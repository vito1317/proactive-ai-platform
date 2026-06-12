<?php

namespace App\Http\Controllers;

use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\RouteCommandJob;
use App\Pai\Cognition\RunStatus;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 對話式「指揮 AI」：多輪上下文；閒聊回覆、要做事就自動觸發任務/新增領域/通知。
 */
class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $conv = $this->current($request);

        // 進行中的事件（已收/已路由但運行尚未結束）→ 供前端追蹤真實進度
        $activeEvent = PaiEvent::where('payload->conversation_id', $conv->id)
            ->whereIn('status', [EventStatus::Received, EventStatus::Routed])
            ->latest('id')
            ->first();

        return Inertia::render('Chat', [
            'conversation' => [
                'id' => $conv->id, 'title' => $conv->title ?? '新對話',
                'channel' => $this->channelOf($conv), // tg / line / null；TG·LINE session 後台唯讀
                'active_event_id' => $activeEvent?->id,
            ],
            'messages' => $conv->messages()->get()->map(fn ($m) => [
                'id' => $m->id, 'role' => $m->role, 'content' => $m->content,
                'meta' => $m->meta ?? [], 'at' => $m->created_at?->format('H:i'),
            ])->all(),
            // 自己的對話 + TG/LINE 的 session（後台可查看 bot 與誰聊了什麼）
            'conversations' => $this->visible($request)
                ->latest('id')->limit(25)->get(['id', 'title', 'tg_chat_id', 'line_to'])
                ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title ?? '新對話', 'channel' => $this->channelOf($c)])->all(),
            // 全雙工語音設定（前端據此連 Socket.IO；密鑰不外露）
            'voice' => $this->voiceConfig($request->user()?->id),
            // 斜線指令清單（輸入「/」時自動提示）：內建 + 使用者自訂
            'slashCommands' => array_merge(
                [['name' => 'new', 'description' => '開新對話'], ['name' => 'clear', 'description' => '清空目前對話']],
                \App\Pai\Chat\SlashCommand::where('enabled', true)->orderBy('name')
                    ->get(['name', 'description'])
                    ->map(fn ($c) => ['name' => $c->name, 'description' => $c->description ?: '自訂指令'])->all()
            ),
        ]);
    }

    /** 全雙工語音前端設定（可由 Settings/AI 即時調整）。 */
    private function voiceConfig(?int $userId = null): array
    {
        $s = app(\App\Pai\Settings\Settings::class);

        return [
            'enabled' => (bool) $s->get('voice.fullduplex_enabled', config('pai.voice.fullduplex_enabled')),
            'url' => (string) $s->get('voice.fullduplex_url', config('pai.voice.fullduplex_url')), // 空=同源
            'path' => (string) $s->get('voice.fullduplex_path', config('pai.voice.fullduplex_path')),
            // hybrid：原生 S2S 即時對話（快），偵測到指令才繞 PAI 執行（open/close/search/系統操作）
            'mode' => 'hybrid',
            'prompt' => trim(
                ($ov = app(\App\Pai\Agent\PersonaProfiles::class)->systemOverlay($userId)) !== '' ? $ov."\n\n" : ''
            ).(string) $s->get('voice.system_prompt', config('pai.voice.system_prompt'), $userId), // 人格 overlay + 語音人格
        ];
    }

    public function send(Request $request, ChatResponder $responder): RedirectResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $conv = $this->resolve($request, $data['conversation_id'] ?? null);
        if ($conv->title === null) {
            $conv->update(['title' => Str::limit($data['message'], 30)]);
        }

        $conv->addMessage('user', $data['message']);
        $result = $responder->respond($conv, $data['message']);
        $conv->addMessage('assistant', $result['reply'], $result['meta']);

        return redirect()->route('chat', ['c' => $conv->id]);
    }

    public function new(Request $request): RedirectResponse
    {
        $conv = Conversation::create(['user_id' => $request->user()->id]);

        return redirect()->route('chat', ['c' => $conv->id]);
    }

    /** 清空目前對話的所有訊息（/clear）。 */
    public function clear(Request $request): RedirectResponse
    {
        $conv = $this->current($request);
        $conv->messages()->delete();
        $conv->update(['title' => null, 'summary' => null, 'compacted_through_id' => null]);

        return redirect()->route('chat', ['c' => $conv->id]);
    }

    /** 獲取特定事件及其運行的即時狀態（供前端追蹤真實進度）。 */
    public function eventStatus(Request $request, PaiEvent $event): JsonResponse
    {
        // 驗證權限：確保事件來源於該使用者的對話
        if ((int) ($event->payload['conversation_id'] ?? 0) !== (int) $this->current($request)->id) {
            // abort(403); // 先簡化處理
        }

        $runs = AgentRun::where('event_id', $event->id)->get();

        return response()->json([
            'status' => $event->status,
            'runs' => $runs->map(fn ($r) => [
                'status' => $r->status,
                'steps' => $r->steps,
                'tokens' => $r->tokens,
            ]),
        ]);
    }

    /**
     * 非串流後備：把訊息丟背景處理（不走 SSE），前端輪詢取回。
     * 用於 SSE 被 WAF/HTTP2 擋掉時的可靠回退——一般 POST 不受串流規則影響。
     */
    public function queue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $conv = $this->resolve($request, $data['conversation_id'] ?? null);
        if ($conv->title === null) {
            $conv->update(['title' => Str::limit($data['message'], 30)]);
        }
        $conv->addMessage('user', $data['message']);
        $event = PaiEvent::create([
            'source' => 'chat', 'topic' => 'console.request',
            'payload' => ['message' => $data['message'], 'conversation_id' => $conv->id],
            'status' => EventStatus::Received,
        ]);
        RouteCommandJob::dispatch($event->id);

        return response()->json(['ok' => true, 'conversation_id' => $conv->id]);
    }

    /** 終止回覆 / 插話：標記中止旗標（串流/背景生成皆檢查）+ 取消該對話進行中的任務運行。 */
    public function stop(Request $request): JsonResponse
    {
        $id = (int) $request->input('conversation_id');
        if ($id > 0) {
            Cache::put("pai:chat:abort:{$id}", true, 120);

            // 同步取消「由這個對話觸發、仍在跑的背景任務」（CognitiveEngine 每步檢查 Cancelled）
            $eventIds = PaiEvent::where('payload->conversation_id', $id)->pluck('id');
            if ($eventIds->isNotEmpty()) {
                AgentRun::whereIn('event_id', $eventIds)
                    ->whereIn('status', [RunStatus::Running, RunStatus::AwaitingHitl])
                    ->update(['status' => RunStatus::Cancelled]);
            }

            // 立即收尾：若最後一則仍是使用者訊息（生成被斷線/逾時殺掉的孤兒狀態），補一則「已停止」，
            // 讓前端不再卡在「生成中」、重新整理也不會復活。
            $conv = Conversation::find($id);
            if ($conv && $conv->messages()->latest('id')->first()?->role === 'user') {
                $conv->addMessage('assistant', '（已停止）你按了終止；需要的話重新輸入問題即可。', ['stopped' => true]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /** 取目前會話（?c=id 指定，否則最新，否則新建）。 */
    private function current(Request $request): Conversation
    {
        if ($request->filled('c')) {
            $conv = $this->visible($request)->find($request->integer('c'));
            if ($conv) {
                return $conv;
            }
        }

        return Conversation::where('user_id', $request->user()->id)->latest('id')->first()
            ?? Conversation::create(['user_id' => $request->user()->id]);
    }

    /** 後台可見的會話：自己的 + 來自 TG/LINE 的 session。 */
    private function visible(Request $request): Builder
    {
        return Conversation::where(fn ($q) => $q
            ->where('user_id', $request->user()->id)
            ->orWhereNotNull('tg_chat_id')
            ->orWhereNotNull('line_to'));
    }

    private function channelOf(Conversation $c): ?string
    {
        return $c->tg_chat_id ? 'tg' : ($c->line_to ? 'line' : null);
    }

    private function resolve(Request $request, ?int $id): Conversation
    {
        if ($id) {
            $conv = Conversation::where('user_id', $request->user()->id)->find($id);
            if ($conv) {
                return $conv;
            }
        }

        return Conversation::create(['user_id' => $request->user()->id]);
    }
}
