<?php

namespace App\Pai\Agent;

use App\Pai\Call\OutboundCall;
use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\RunStatus;
use App\Pai\Perception\PaiEvent;
use App\Pai\Watch\WatchTask;

/**
 * 即時作業流 (Agent Ops)：彙總所有「運行中」的 agent 與其當前動作細節。
 * 三種活體：協調者認知運行（ReAct 步驟鏈）、視覺守望（盯手機畫面）、AI 外撥電話（逐字稿）。
 * Web 中控台 AgentOpsFlow 與 Android App 都吃這份 snapshot。
 */
class AgentOps
{
    /** @return list<array<string, mixed>> */
    public static function snapshot(?int $uid, bool $isAdmin): array
    {
        $convIds = \App\Pai\Chat\Conversation::where('user_id', $uid)->pluck('id')->all();
        $eventIds = PaiEvent::where(function ($q) use ($convIds, $isAdmin) {
            $q->whereIn('payload->conversation_id', $convIds);
            if ($isAdmin) {
                $q->orWhereNull('payload->conversation_id');
            }
        })->pluck('id')->all();

        $agents = [];

        // 0) 對話中的 agent（網頁/語音/TG/LINE/通勤/自動化/主動思考）—— SkillRunner 心跳
        foreach (ChatActivity::active($uid) as $d) {
            $agents[] = [
                'id' => 'chat-'.$d['conversation_id'],
                'kind' => 'chat',
                'name' => ($d['source'] ?? '對話').' Agent',
                'status' => 'running',
                'title' => (string) ($d['goal'] ?? ''),
                'steps' => array_values(array_map(static fn ($t) => ['text' => (string) $t], (array) ($d['steps'] ?? []))),
                'elapsed' => isset($d['started_at']) ? max(0, now()->timestamp - (int) $d['started_at']) : null,
            ];
        }

        // 1) 協調者認知運行（running / awaiting_hitl）—— 完整 ReAct 步驟鏈
        $runs = AgentRun::query()
            ->whereIn('status', [RunStatus::Running, RunStatus::AwaitingHitl])
            ->whereIn('event_id', $eventIds)
            ->with('event:id,topic')
            ->latest('id')->limit(8)->get();
        foreach ($runs as $r) {
            $steps = array_values(array_map(static fn ($s) => [
                'action' => (string) ($s['action'] ?? ''),
                'input' => is_array($s['action_input'] ?? null) ? $s['action_input'] : [],
                'thought' => isset($s['thought']) ? mb_substr((string) $s['thought'], 0, 300) : null,
                'observation' => isset($s['observation']) ? mb_substr((string) $s['observation'], 0, 400) : null,
            ], is_array($r->steps) ? $r->steps : []));
            $agents[] = [
                'id' => 'run-'.$r->id,
                'kind' => 'coordinator',
                'name' => $r->coordinator,
                'status' => $r->status->value,
                'title' => $r->event?->topic ?? $r->domain,
                'domain' => $r->domain,
                'steps' => $steps,
                'tokens' => (int) $r->tokens,
                'elapsed' => $r->created_at ? (int) abs(now()->diffInSeconds($r->created_at)) : null,
            ];
        }

        // 2) 視覺守望（幫我盯著手機/螢幕畫面）—— last_desc 是 AI 最後看到的畫面
        foreach (WatchTask::where('user_id', $uid)->where('status', 'active')->latest('id')->limit(8)->get() as $w) {
            $agents[] = [
                'id' => 'watch-'.$w->id,
                'kind' => 'watch',
                'name' => '視覺守望',
                'status' => 'active',
                'title' => $w->goal,
                'node' => $w->node,
                'detail' => $w->last_desc,
                'runs' => (int) $w->run_count,
                'interval' => (int) $w->interval_sec,
                'last_run_at' => $w->last_run_at?->toIso8601String(),
                'expires_at' => $w->expires_at?->toIso8601String(),
            ];
        }

        // 3) AI 外撥電話（Twilio 回合制對話）—— 顯示目標、回合數、最後一句
        foreach (OutboundCall::where('user_id', $uid)->whereIn('status', ['pending', 'in_progress'])->latest('id')->limit(4)->get() as $c) {
            $t = (array) ($c->transcript ?? []);
            $last = $t !== [] ? $t[count($t) - 1] : null;
            $agents[] = [
                'id' => 'call-'.$c->id,
                'kind' => 'call',
                'name' => 'AI 外撥電話',
                'status' => $c->status,
                'title' => $c->goal,
                'to' => $c->to_number,
                'turns' => (int) $c->turns,
                'lastLine' => $last ? (((($last['role'] ?? '') === 'ai') ? '我方AI' : '對方').'：'.mb_substr((string) ($last['text'] ?? ''), 0, 120)) : null,
                'elapsed' => $c->created_at ? (int) abs(now()->diffInSeconds($c->created_at)) : null,
            ];
        }

        return $agents;
    }
}
