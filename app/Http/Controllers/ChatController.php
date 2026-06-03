<?php

namespace App\Http\Controllers;

use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('Chat', [
            'conversation' => [
                'id' => $conv->id, 'title' => $conv->title ?? '新對話',
                'channel' => $this->channelOf($conv), // tg / line / null；TG·LINE session 後台唯讀
            ],
            'messages' => $conv->messages()->get()->map(fn ($m) => [
                'id' => $m->id, 'role' => $m->role, 'content' => $m->content,
                'meta' => $m->meta ?? [], 'at' => $m->created_at?->format('H:i'),
            ])->all(),
            // 自己的對話 + TG/LINE 的 session（後台可查看 bot 與誰聊了什麼）
            'conversations' => $this->visible($request)
                ->latest('id')->limit(25)->get(['id', 'title', 'tg_chat_id', 'line_to'])
                ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title ?? '新對話', 'channel' => $this->channelOf($c)])->all(),
        ]);
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
