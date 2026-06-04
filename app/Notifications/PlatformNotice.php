<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** 平台通用通知（中控台鈴鐺）：自動判斷處理結果等。 */
class PlatformNotice extends Notification
{
    public function __construct(
        public readonly string $message,
        public readonly string $kind = 'info',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->kind, 'message' => $this->message];
    }
}
