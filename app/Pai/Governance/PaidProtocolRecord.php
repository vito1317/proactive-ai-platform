<?php

namespace App\Pai\Governance;

use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\CognitiveEngine;
use App\Pai\Cognition\RunStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PAID Protocol 紀錄輸出（Proactive Agent Infrastructure with Dynamic-finetuning）。
 *
 * 每次 AgentRun 完成（或 HITL 決定後）輸出一份 6 層 JSON 到
 * pai.governance.records_path（路徑由 config 決定，未寫死；檔名以 run id 命名，
 * 重放/更新冪等覆寫）。與 pai-framework 的 protocol.py 互通：寫出 paid_protocol_version，
 * 同時保留舊 pai_protocol_version 鏡像欄位讓舊讀取器（pai-framework < 0.1.3）仍可驗證。
 */
class PaidProtocolRecord
{
    /** 協定版本（協定更名為 PAID 後 bump 至 1.2）。 */
    public const VERSION = '1.2';

    /** 協定全名（寫進紀錄供他人系統識別）。 */
    public const PROTOCOL = 'PAID';

    /** 自主等級 → 交付模式（與 framework LEVEL_TO_DELIVERY 一致）。 */
    private const LEVEL_TO_DELIVERY = [
        ProactivityPolicy::OBSERVE => 'level_0_silent',
        ProactivityPolicy::SUGGEST => 'level_1_soft_nudge',
        ProactivityPolicy::ASK => 'level_2_approval',
        ProactivityPolicy::ACT => 'level_0_silent',   // 自動執行 = 無聲完成，事後可查
    ];

    /** 輸出（或更新）一份紀錄；失敗不影響主流程。回傳檔案路徑（失敗回 null）。 */
    public static function write(AgentRun $run): ?string
    {
        try {
            $dir = (string) config('pai.governance.records_path');
            if ($dir === '') {
                return null;
            }
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true)) {
                return null;
            }
            $path = $dir.'/'.sprintf('paid_run_%06d.paid.json', $run->id);
            file_put_contents($path, json_encode(self::build($run), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $path;
        } catch (Throwable $e) {
            Log::warning('PAID Protocol 紀錄輸出失敗', ['run_id' => $run->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, mixed> 6 層 PAID Protocol 紀錄 */
    public static function build(AgentRun $run): array
    {
        $event = $run->event;
        $actions = collect($run->actions);
        $urgency = $event ? CognitiveEngine::urgencyOf($event) : 0.5;
        $confidence = (float) ($actions->max(fn ($a) => (float) ($a['confidence'] ?? 0.7)) ?? 0.7);
        $granted = $actions->map(fn ($a) => (int) ($a['granted_level'] ?? self::levelFromStatus((string) ($a['status'] ?? ''))));
        $topLevel = (int) ($granted->max() ?? ProactivityPolicy::OBSERVE);
        $awaiting = $actions->contains(fn ($a) => ($a['status'] ?? null) === 'awaiting_approval');

        // 6_adaptation：所有動作都有人類定論才不是 pending
        $feedback = 'pending';
        if (! $awaiting && $actions->isNotEmpty()) {
            $rejected = $actions->where('status', 'rejected')->count();
            $feedback = $rejected === $actions->count() ? 'rejected' : ($rejected > 0 ? 'modified' : 'accepted');
        }

        return [
            'paid_protocol_version' => self::VERSION,
            'pai_protocol_version' => self::VERSION,   // 向後相容鏡像：舊讀取器仍可驗證
            'protocol' => self::PROTOCOL,
            'record_id' => sprintf('paid_%s_run%06d', $run->created_at?->format('Ymd') ?? now()->format('Ymd'), $run->id),
            'timestamp' => now('UTC')->toIso8601String(),

            '1_perception' => [
                'trigger_source' => $event->source ?? 'unknown',
                'event_type' => $event->topic ?? 'unknown',
                'raw_data_summary' => $event->payload ?? [],
            ],
            '2_context' => [
                'user_current_state' => 'unknown',
                'relevant_memory' => array_slice($run->findings ?? [], 0, 5),
                'action_history' => $actions->map(fn ($a) => ($a['action'] ?? '').'='.($a['status'] ?? ''))->values()->all(),
            ],
            '3_anticipation' => [
                'predicted_intent' => (string) ($run->summary ?? ''),
                'actions' => $actions->map(fn ($a) => [
                    'action' => $a['action'] ?? '',
                    'rationale' => $a['rationale'] ?? '',
                    'risk' => $a['risk'] ?? 'low',
                    'confidence' => (float) ($a['confidence'] ?? 0.7),
                    'granted_level' => (int) ($a['granted_level'] ?? self::levelFromStatus((string) ($a['status'] ?? ''))),
                    'gate_reason' => $a['gate_reason'] ?? '',
                ])->values()->all(),
                'urgency_score' => $urgency,
                'confidence_score' => $confidence,
                'interruption_cost' => (float) config('pai.governance.interruption_cost', 0.0),
                'requested_level' => $topLevel,
                'granted_level' => $topLevel,
            ],
            '4_execution' => [
                'actions_taken' => $actions->whereIn('status', ['executed'])->map(fn ($a) => [
                    'tool' => $a['action'] ?? '',
                    'status' => 'ok',
                    'result' => mb_substr((string) ($a['result'] ?? ''), 0, 500),
                ])->values()->all(),
                'status' => match (true) {
                    $run->status === RunStatus::Failed => 'failed',
                    $awaiting => 'awaiting_approval',
                    $actions->contains(fn ($a) => ($a['status'] ?? null) === 'executed') => 'executed',
                    default => 'not_executed',
                },
            ],
            '5_delivery' => [
                'delivery_mode' => $awaiting ? 'level_2_approval' : (self::LEVEL_TO_DELIVERY[$topLevel] ?? 'level_0_silent'),
                'requires_human_approval' => $awaiting,
            ],
            '6_adaptation' => [
                'user_feedback' => $feedback,
                'learning_adjustment' => null,
            ],
        ];
    }

    /** 舊紀錄（未帶 granted_level 的 action）由 status 反推等級。 */
    private static function levelFromStatus(string $status): int
    {
        return match ($status) {
            'executed' => ProactivityPolicy::ACT,
            'awaiting_approval', 'rejected' => ProactivityPolicy::ASK,
            'suggested' => ProactivityPolicy::SUGGEST,
            default => ProactivityPolicy::OBSERVE,
        };
    }
}
