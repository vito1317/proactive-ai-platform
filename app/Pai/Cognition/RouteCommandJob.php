<?php

namespace App\Pai\Cognition;

use App\Models\User;
use App\Notifications\PlatformNotice;
use App\Pai\Domains\DomainPackGenerator;
use App\Pai\Notify\Notifier;
use App\Pai\Notify\NotifyAssistant;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use App\Pai\Perception\Severity;
use App\Pai\Settings\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * 「指揮 AI」單一輸入框的自動分派：先用 MetaRouter 判斷類別，
 * 再分別處理 — 執行任務 / 新增領域 / 設定通知。使用者不需選任何模式。
 */
class RouteCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $eventId) {}

    public function handle(
        MetaRouter $meta,
        IntentClassifier $classifier,
        DomainPackGenerator $generator,
        NotifyAssistant $assistant,
        Notifier $notifier,
        Settings $settings,
    ): void {
        $event = PaiEvent::find($this->eventId);
        if ($event === null) {
            return;
        }
        $msg = (string) ($event->payload['message'] ?? '');

        match ($meta->classify($msg)['category']) {
            'new_domain' => $this->newDomain($event, $msg, $generator),
            'configure_notify' => $this->configureNotify($event, $msg, $assistant, $settings, $notifier),
            default => $this->task($event, $msg, $classifier),
        };
    }

    private function task(PaiEvent $event, string $msg, IntentClassifier $classifier): void
    {
        $r = $classifier->classify($msg);
        if ($r['domain'] === null) {
            $event->update(['status' => EventStatus::Ignored, 'intent' => 'user-request', 'note' => '[任務] 無對應領域：'.$r['rationale']]);

            return;
        }
        $event->update([
            'topic' => $r['topic'], 'domain' => $r['domain'], 'intent' => 'user-request',
            'severity' => Severity::from($r['severity']), 'status' => EventStatus::Routed,
            'note' => '[任務] '.$r['rationale'],
        ]);
        RunCoordinatorJob::dispatch($event->id, $event->domain);
        // 任務的結果會以認知運行軌跡呈現，不另發鈴鐺
    }

    private function newDomain(PaiEvent $event, string $msg, DomainPackGenerator $generator): void
    {
        $res = $generator->generate($msg);
        if ($res['valid']) {
            $domain = $res['manifest']['domain'];
            file_put_contents(base_path("packs/{$domain}.yaml"), $res['yaml']);
            $event->update(['intent' => 'new-domain', 'status' => EventStatus::Normalized, 'note' => "🧩 已建立領域包 {$domain}"]);
            $this->notice("🧩 已依你的描述建立領域包「{$domain}」並啟用。");
        } else {
            $event->update(['intent' => 'new-domain', 'status' => EventStatus::Failed, 'note' => '領域包生成失敗：'.implode('；', $res['errors'])]);
            $this->notice('🧩 領域包生成失敗，請再描述清楚一點。', 'error');
        }
    }

    private function configureNotify(PaiEvent $event, string $msg, NotifyAssistant $assistant, Settings $settings, Notifier $notifier): void
    {
        $r = $assistant->extract($msg);
        foreach ($r['fields'] as $key => $value) {
            $settings->set($key, $value);
        }
        $tested = false;
        if ($r['fields'] !== [] && ($notifier->configured()[$r['channel']] ?? false)) {
            $tested = ! empty(array_filter($notifier->send('✅ PAI 通知測試：設定成功。')));
        }
        $event->update(['intent' => 'configure-notify', 'status' => EventStatus::Normalized, 'note' => '🔔 '.$r['reply']]);
        $this->notice('🔔 '.$r['reply'].($tested ? '（已發送測試訊息）' : ''));
    }

    private function notice(string $message, string $kind = 'info'): void
    {
        // 中控台鈴鐺
        $users = User::all();
        if ($users->isNotEmpty()) {
            Notification::send($users, new PlatformNotice($message, $kind));
        }
        // 同步推到已設定的外部平台（Telegram/LINE/webhook）
        app(\App\Pai\Notify\Notifier::class)->send($message);
    }
}
