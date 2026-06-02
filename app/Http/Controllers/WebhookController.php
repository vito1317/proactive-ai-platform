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
 */
class WebhookController extends Controller
{
    public function store(Request $request, string $source): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
        ]);

        $event = PaiEvent::create([
            'source' => $source,
            'topic' => $validated['topic'],
            'payload' => $request->except('topic'),
            'status' => EventStatus::Received,
        ]);

        IngestEventJob::dispatch($event->id);

        return response()->json([
            'event_id' => $event->id,
            'status' => $event->status->value,
        ], 202);
    }
}
