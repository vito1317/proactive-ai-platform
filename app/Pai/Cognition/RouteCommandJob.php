<?php

namespace App\Pai\Cognition;

use App\Models\User;
use App\Notifications\PlatformNotice;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Notify\Notifier;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * 「指揮 AI」單一輸入框的自動分派：交給對話大腦 ChatResponder 統一處理
 * （閒聊 / 任務 / 新增領域 / 設定通知 / 平台技能），結果回鈴鐺 + 外部通知。
 * 不再有「無對應領域就靜默 ignored」——任何輸入都會得到回覆。
 */
class RouteCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $eventId) {}

    public function handle(ChatResponder $responder): void
    {
        $event = PaiEvent::find($this->eventId);
        if ($event === null) {
            return;
        }
        $msg = trim((string) ($event->payload['message'] ?? ''));
        if ($msg === '') {
            $event->update(['status' => EventStatus::Ignored, 'note' => '空指令']);

            return;
        }

        // 沿用來源對話（chat 觸發時帶 conversation_id）；否則開一個（也讓使用者能在 /chat 追溯）
        $convId = $event->payload['conversation_id'] ?? null;
        $conv = ($convId ? Conversation::find($convId) : null)
            ?? Conversation::create(['user_id' => $event->payload['user_id'] ?? null, 'title' => Str::limit($msg, 30)]);
        if ($conv->wasRecentlyCreated) {
            $conv->addMessage('user', $msg);
        }

        $voiceConv = ($event->source ?? '') === 'voice' ? (int) ($event->payload['conversation_id'] ?? 0) : 0;
        if ($voiceConv > 0) {
            $this->pushVoice($voiceConv, '🔎 開始查資料、整理中…', progress: true);
        }
        try {
            $r = $this->respondFinal($responder, $conv, $msg, function (string $step) use ($voiceConv) {
                // 過程步驟即時推到語音畫面（只顯示不念），讓使用者看到在動
                if ($voiceConv > 0) {
                    $this->pushVoice($voiceConv, $step, progress: true);
                }
            });
            $event->update([
                'status' => EventStatus::Normalized,
                'intent' => 'console:'.($r['meta']['category'] ?? 'reply'),
                'note' => mb_substr($r['reply'], 0, 250),
            ]);
            // 重型結果：存成檔案（方便外出看 / 下載），把連結附在回覆與通知
            $fileLink = $this->saveResultFile($event->id, $msg, $r['reply']);
            $replyWithLink = $fileLink ? $r['reply']."\n\n📄 完整內容：".$fileLink : $r['reply'];

            // 把真正的回覆寫回對話（SSE 端對 action 類只串了暫態、未存訊息）；前端輪詢帶出。
            // 若是 task，respond 內已建立帶 domain 的事件並 dispatch 協調者，最終結果由 RunCoordinatorJob 再回貼。
            $conv->addMessage('assistant', $replyWithLink, $r['meta']);
            $this->notice($replyWithLink);
            // 來源是語音 → 若該 /voice 仍連線中，把結果念回去
            if (($event->source ?? '') === 'voice') {
                $this->pushVoice((int) ($event->payload['conversation_id'] ?? 0), $r['reply']);
            }
        } catch (Throwable $e) {
            $event->update(['status' => EventStatus::Failed, 'note' => '處理失敗：'.$e->getMessage()]);
            $this->notice('指令處理失敗：'.$e->getMessage(), 'error');
        }
    }

    /**
     * 背景一次性任務版的 respond：閒聊/生成類加一道「現在就給完整最終結果」指令，
     * 杜絕「我會幫你規劃…請稍等」這種沒有下文的空頭支票（背景任務不會有下一輪）。
     *
     * @return array{reply: string, meta: array<string, mixed>}
     */
    private function respondFinal(ChatResponder $responder, Conversation $conv, string $msg, ?callable $onStep = null): array
    {
        // 1) 先讓多輪技能代理實際執行（ReAct：可連續上網查證、跑指令，再彙整）——
        //    重型任務丟背景的意義就是這個，不是叫 LLM 單發腦補。
        try {
            $skill = $responder->skills()->handle(
                $conv,
                $msg."\n（系統：這是背景任務。若是訂票/訂房/排行程/比價/在特定網站操作，請『用瀏覽器工具 browser_* 在使用者桌面節點實際操作網頁』（導航→看元素→填→點），不要只靠文字搜尋；涉及付款/送出前停在確認頁、回報並等使用者確認，不要自動下單。其餘需查證的事實實際用工具查（如上網）。即使部分查詢失敗，也要用已知常識補齊並註明「建議出發前確認」，最後一定要給出完整可用的結果，絕對不要回覆『無法提供』。）",
                $onStep,
            );
            if (empty($skill['meta']['no_skill']) && trim((string) ($skill['reply'] ?? '')) !== '') {
                return ['reply' => $skill['reply'], 'meta' => $skill['meta'] ?? []];
            }
        } catch (Throwable) {
            // 技能代理失敗 → 跌回單發完成
        }

        $r = $responder->route($conv, $msg);
        if (! $r['stream']) {
            return ['reply' => $r['reply'], 'meta' => $r['meta'] ?? []];
        }
        $messages = $r['messages'];
        $messages[] = ['role' => 'system', 'content' => '提醒：這是一次性的背景任務，使用者不會再追問細節。'
            .'請「現在」直接產出完整、可直接使用的最終結果——例如完整行程表（每天分時段：地點、交通方式、用餐安排與理由）。'
            .'絕對不要說「請稍等」「我會再提供」「正在規劃」，也不要只回確認句或反問。缺少的偏好就自行做合理假設並註明。'];

        return [
            'reply' => trim(app(\App\Pai\Cognition\LlmClient::class)->chat($messages, ['max_tokens' => 4096])),
            'meta' => ['category' => 'chat'],
        ];
    }

    /** 把任務結果存成可下載檔案，回傳公開連結（失敗回空字串）。 */
    private function saveResultFile(int $eventId, string $title, string $reply): string
    {
        try {
            $dir = storage_path('app/public/results');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $name = 'result-'.$eventId.'.md';
            file_put_contents("{$dir}/{$name}", "# {$title}\n\n".$reply."\n");

            return rtrim((string) config('app.url'), '/').'/storage/results/'.$name;
        } catch (Throwable) {
            return '';
        }
    }

    /** 把結果念回正在連線中的 /voice（透過 voice_server 的 /voice/push）。 */
    private function pushVoice(int $conversationId, string $text, bool $progress = false): void
    {
        if ($conversationId <= 0) {
            return;
        }
        try {
            $url = (string) config('pai.voice.push_url', 'http://127.0.0.1:8891/voice/push');
            \Illuminate\Support\Facades\Http::timeout($progress ? 8 : 60)->post($url, [
                'conversation_id' => $conversationId,
                'text' => $text,
                'progress' => $progress,
                'secret' => (string) (app(\App\Pai\Settings\Settings::class)->get('voice.agent_secret', config('services.voice.agent_secret'))),
            ]);
        } catch (Throwable) {
            // /voice 沒連線或推送失敗 → 略過（結果已在對話 + 通知）
        }
    }

    private function notice(string $message, string $kind = 'info'): void
    {
        // 中控台鈴鐺
        $users = User::all();
        if ($users->isNotEmpty()) {
            Notification::send($users, new PlatformNotice($message, $kind));
        }
        // 同步推到已設定的外部平台（Telegram/LINE/webhook）
        app(Notifier::class)->send($message);
    }
}
