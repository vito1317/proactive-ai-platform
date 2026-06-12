<?php

namespace App\Pai\Governance;

use App\Pai\Cognition\AgentRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 把人類對 paigent 節點事件的核准/駁回回送給該節點的 ReflectiveMemory
 * （PAID Protocol 的 self-finetuning 第 1 層 / Dynamic-finetuning）。
 *
 * 不寫死節點位址：回送 URL 來自事件 payload 的 pai_feedback_url（節點推 webhook 時
 * 自報）。沒有該欄位的事件（非 paigent 來源）會被安靜略過 —— 對任何使用者的任意節點
 * 都通用，平台端零設定。
 */
class GuardianFeedback
{
    /** 從一次 HITL 定論回送回饋；$approved=true→accepted、false→rejected。 */
    public static function fromRun(AgentRun $run, bool $approved): void
    {
        $payload = $run->event?->payload;
        if (! is_array($payload)) {
            return;
        }
        self::send($payload, $approved ? 'accepted' : 'rejected');
    }

    /**
     * @param  array<string, mixed>  $eventPayload  收到的節點 webhook payload（存於 PaiEvent）
     * @param  string  $feedback  accepted|rejected|modified|ignored
     */
    public static function send(array $eventPayload, string $feedback): void
    {
        $url = (string) ($eventPayload['pai_feedback_url'] ?? '');
        if ($url === '') {
            return; // 非 paigent 自報來源 → 無回送對象
        }

        // 回溯鍵與原始決策：節點推來時放在 intent.params.intent（SUGGEST wrapper）或 intent
        $inner = $eventPayload['intent']['params']['intent'] ?? $eventPayload['intent'] ?? [];

        try {
            Http::timeout(8)->acceptJson()->post($url, [
                'event_id' => (string) ($inner['event_id'] ?? ''),
                'action' => (string) ($inner['action'] ?? ''),
                'rationale' => (string) ($inner['rationale'] ?? ''),
                'feedback' => $feedback,
            ]);
        } catch (Throwable $e) {
            // 回饋是強化用途，失敗不影響核准主流程
            Log::info('節點回饋回送失敗（不影響核准）', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }
}
