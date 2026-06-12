<?php

namespace App\Http\Controllers;

use App\Pai\Perception\EventStatus;
use App\Pai\Perception\IngestEventJob;
use App\Pai\Perception\PaiEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L1 感知層的事件入口。外部系統（SIEM / Git / CI…）以
 *   POST /webhooks/{source}   body: { "topic": "...", ...payload }
 * 推送事件。落地後丟到 queue 做正規化 + 路由，立即回 202。
 *
 * 也接受 paigent（pip install paigent）節點哨兵的兩種原生格式，零客製直接接上：
 *  - WebhookNotifier payload：{ "title", "body", "intent": {action, urgency, confidence…} }
 *  - PAI Protocol v1.1 紀錄：{ "pai_protocol_version", "1_perception": {...}, ... }
 */
class WebhookController extends Controller
{
    public function store(Request $request, string $source): JsonResponse
    {
        $payload = $this->normalizePaigent($request->all());

        $validated = validator($payload, [
            'topic' => ['required', 'string', 'max:255'],
        ])->validate();

        $event = PaiEvent::create([
            'source' => $source,
            'topic' => $validated['topic'],
            'payload' => array_diff_key($payload, ['topic' => null]),
            'status' => EventStatus::Received,
        ]);

        IngestEventJob::dispatch($event->id);

        return response()->json([
            'event_id' => $event->id,
            'status' => $event->status->value,
        ], 202);
    }

    /**
     * paigent 原生格式 → 平台事件格式（已帶 topic 的標準格式原樣通過）。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePaigent(array $payload): array
    {
        if (isset($payload['topic'])) {
            return $payload;
        }

        // PAI Protocol v1.1 完整紀錄 → topic = 1_perception.event_type
        if (isset($payload['pai_protocol_version'])) {
            $topic = (string) ($payload['1_perception']['event_type'] ?? 'pai.record');

            return ['topic' => $topic !== '' ? $topic : 'pai.record', ...$payload];
        }

        // WebhookNotifier payload（intent 內含 action/urgency/confidence）。
        // SUGGEST 流程會包一層 __notify__ wrapper（原始意圖在 intent.params.intent）→ 解開取語意化動作名
        if (is_array($payload['intent'] ?? null)) {
            $action = (string) ($payload['intent']['params']['intent']['action']
                ?? $payload['intent']['action'] ?? 'unknown');
            $action = trim($action, '_');   // __notify__ → notify
            if ($action === '') {
                $action = 'unknown';
            }

            return ['topic' => 'node.intent.'.$action, ...$payload];
        }

        return $payload; // 缺 topic 且非 paigent 格式 → 交給驗證回 422
    }
}
