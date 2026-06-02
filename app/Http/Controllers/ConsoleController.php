<?php

namespace App\Http\Controllers;

use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\ClassifyCommandJob;
use App\Pai\Cognition\RunStatus;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\IngestEventJob;
use App\Pai\Perception\PaiEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 中控台 (Control Console)：人類「看見」並「指揮」主動式 AI 的單一入口。
 * - 看：已載入領域、即時事件流、狀態統計。
 * - 指揮：注入事件 / 下指令，喚醒對應領域協調者。
 */
class ConsoleController extends Controller
{
    public function index(Request $request, DomainRegistry $registry): Response
    {
        $packs = array_values($registry->all());

        return Inertia::render('Console', [
            'platform' => 'PAI',
            'domains' => array_map(static fn ($p) => $p->toArray(), $packs),

            // 指令面板可快速選用的「領域 → 訂閱主題」
            'commandTargets' => array_map(static fn ($p) => [
                'domain' => $p->domain,
                'coordinator' => $p->coordinator,
                'topics' => $p->eventTopics(),
            ], $packs),

            // 即時事件流（輪詢時只重載這幾個 prop）
            'events' => $this->recentEvents(),
            'runs' => $this->recentRuns(),
            'stats' => $this->stats(),
        ]);
    }

    /**
     * 下指令：注入一筆事件到 L1 匯流排，喚醒對應領域。
     */
    public function dispatchEvent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:64'],
            'topic' => ['required', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
        ]);

        $event = PaiEvent::create([
            'source' => $data['source'],
            'topic' => $data['topic'],
            'payload' => $data['payload'] ?? [],
            'status' => EventStatus::Received,
        ]);

        IngestEventJob::dispatch($event->id);

        return back()->with('flash', [
            'success' => "已下達指令 #{$event->id}（{$data['topic']}），AI 處理中…",
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function recentEvents(int $limit = 50): array
    {
        return PaiEvent::query()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(static fn (PaiEvent $e) => [
                'id' => $e->id,
                'source' => $e->source,
                'topic' => $e->topic,
                'intent' => $e->intent,
                'severity' => $e->severity?->value,
                'domain' => $e->domain,
                'status' => $e->status->value,
                'note' => $e->note,
                'at' => $e->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * 自然語言指令：一般使用者用白話下指令，AI 自己判斷領域/主題後處理。
     */
    public function ask(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $event = PaiEvent::create([
            'source' => 'console',
            'topic' => 'console.request',
            'payload' => ['message' => $data['message']],
            'status' => EventStatus::Received,
        ]);

        ClassifyCommandJob::dispatch($event->id);

        return back()->with('flash', [
            'success' => "已收到指令 #{$event->id}，AI 正在判斷如何處理…",
        ]);
    }

    /**
     * L5 人機協同：核准 / 駁回某個待核准的動作。
     * 核准 = 放行執行（此處標為 executed；真實執行由 L4 工具完成）。
     */
    public function decide(Request $request, AgentRun $run, \App\Pai\Action\ActionExecutor $executor): RedirectResponse
    {
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'decision' => ['required', 'in:approve,reject'],
        ]);

        $actions = $run->actions;
        $i = $data['index'];
        if (! isset($actions[$i])) {
            abort(404);
        }
        if (($actions[$i]['status'] ?? null) !== 'awaiting_approval') {
            return back()->with('flash', ['error' => '此動作已處理過了。']);
        }

        if ($data['decision'] === 'approve') {
            // 核准 → 真實執行（apply-patch 寫回+重跑測試 / 遏制動作經 EgressGateway）
            $result = $executor->execute($actions[$i], $run->domain);
            $actions[$i]['status'] = 'executed';
            $actions[$i]['result'] = $result['output'];
            $msg = "動作「{$actions[$i]['action']}」已核准並執行：{$result['output']}";
        } else {
            $actions[$i]['status'] = 'rejected';
            $msg = "動作「{$actions[$i]['action']}」已駁回。";
        }
        $run->actions = $actions;

        // 若已無待核准動作 → 運行完成
        $stillPending = collect($actions)->contains(fn ($a) => ($a['status'] ?? null) === 'awaiting_approval');
        $run->status = $stillPending ? RunStatus::AwaitingHitl : RunStatus::Completed;
        $run->save();

        return back()->with('flash', ['success' => $msg]);
    }

    /** 將目前使用者的未讀通知全部標記為已讀。 */
    public function markNotificationsRead(Request $request): RedirectResponse
    {
        $request->user()?->unreadNotifications->markAsRead();

        return back();
    }

    /** @return list<array<string, mixed>> 最近的認知運行軌跡 */
    private function recentRuns(int $limit = 12): array
    {
        return AgentRun::query()
            ->with('event:id,topic')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(static fn (AgentRun $r) => [
                'id' => $r->id,
                'domain' => $r->domain,
                'coordinator' => $r->coordinator,
                'topic' => $r->event?->topic,
                'status' => $r->status->value,
                'steps' => $r->steps,
                'findings' => $r->findings,
                'actions' => $r->actions,
                'summary' => $r->summary,
                'tokens' => $r->tokens,
                'at' => $r->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        $base = [
            'total' => 0,
            EventStatus::Received->value => 0,
            EventStatus::Normalized->value => 0,
            EventStatus::Routed->value => 0,
            EventStatus::Ignored->value => 0,
            EventStatus::Failed->value => 0,
        ];

        $counts = PaiEvent::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $base['total'] = array_sum($counts);

        // L4/L5：跨所有運行統計已執行 / 待核准的動作數
        $acted = 0;
        $hitl = 0;
        foreach (AgentRun::query()->pluck('actions') as $actions) {
            foreach ((array) $actions as $a) {
                if (($a['status'] ?? null) === 'executed') {
                    $acted++;
                } elseif (($a['status'] ?? null) === 'awaiting_approval') {
                    $hitl++;
                }
            }
        }
        $base['acted'] = $acted;
        $base['hitl'] = $hitl;
        $base['runs'] = AgentRun::count();

        return array_merge($base, $counts);
    }
}
