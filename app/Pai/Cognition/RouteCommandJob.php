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

        // 開一個會話讓對話大腦處理（也讓使用者能在 /chat 看到這條指令的來龍去脈）
        $conv = Conversation::create(['user_id' => $event->payload['user_id'] ?? null, 'title' => Str::limit($msg, 30)]);
        $conv->addMessage('user', $msg);

        try {
            $r = $responder->respond($conv, $msg);
            $conv->addMessage('assistant', $r['reply'], $r['meta']);
            $event->update([
                'status' => EventStatus::Normalized,
                'intent' => 'console:'.($r['meta']['category'] ?? 'reply'),
                'note' => mb_substr($r['reply'], 0, 250),
            ]);
            $this->notice($r['reply']);
        } catch (Throwable $e) {
            $event->update(['status' => EventStatus::Failed, 'note' => '處理失敗：'.$e->getMessage()]);
            $this->notice('指令處理失敗：'.$e->getMessage(), 'error');
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
