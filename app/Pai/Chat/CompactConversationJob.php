<?php

namespace App\Pai\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** 背景壓縮一個對話的上下文（不阻塞回覆）。 */
class CompactConversationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $conversationId) {}

    public function handle(ContextCompactor $compactor): void
    {
        $conv = Conversation::find($this->conversationId);
        if ($conv && $compactor->shouldCompact($conv)) {
            $compactor->compact($conv);
        }
    }
}
