<?php

namespace App\Pai\Meeting;

use App\Pai\Cognition\LlmClient;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Notify\Notifier;
use App\Pai\Schedule\ScheduledTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 會議結束 → LLM 出摘要/決議/待辦；有明確期限的待辦直接建成定時任務；
 * 結果推播＋手機彈出完整文件（show_document）。
 */
class MeetingSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(private readonly int $meetingId) {}

    public function handle(LlmClient $llm, Notifier $notifier): void
    {
        $m = Meeting::find($this->meetingId);
        if (! $m || $m->status !== 'summarizing') {
            return;
        }
        \App\Pai\Agent\Tenant::set($m->user_id);
        $transcript = trim((string) $m->transcript);
        if (mb_strlen($transcript) < 30) {
            $m->status = 'error';
            $m->summary = '逐字稿太短（可能沒收到音訊）';
            $m->save();
            try {
                $notifier->send('⚠️ 會議記錄沒有足夠內容可以整理（可能麥克風沒收到聲音）。');
            } catch (Throwable) {
            }

            return;
        }

        try {
            $v = $llm->chatJson([
                ['role' => 'system', 'content' => '你是會議記錄助理。整理逐字稿（來自語音辨識、可能有錯字，用情境推斷），輸出 JSON：'
                    .'{"title":"會議主題一句話","summary":"3~6 點重點摘要（換行分點）","decisions":["決議…"],"action_items":[{"task":"待辦事項（含負責人若有提到）","due":"YYYY-MM-DD HH:MM 或 null"}]}。'
                    .'台灣正體中文。待辦的 due 只在逐字稿明確提到時間時才填（「下週三前」也要換算成日期），否則 null。只輸出 JSON。'],
                ['role' => 'user', 'content' => '現在時間：'.now('Asia/Taipei')->format('Y-m-d H:i')."（供換算相對日期）\n\n會議逐字稿：\n".mb_substr($transcript, 0, 12000)],
            ], ['max_tokens' => 1500]);
        } catch (Throwable $e) {
            Log::warning('會議摘要失敗', ['meeting' => $m->id, 'error' => $e->getMessage()]);
            $m->status = 'error';
            $m->save();

            return;
        }

        $title = trim((string) ($v['title'] ?? '會議記錄'));
        $summary = trim((string) ($v['summary'] ?? ''));
        $decisions = collect((array) ($v['decisions'] ?? []))->filter()->values();
        $items = collect((array) ($v['action_items'] ?? []))
            ->filter(fn ($i) => trim((string) ($i['task'] ?? '')) !== '')->values();

        // 有期限的待辦 → 直接建定時任務（到點由指揮大腦提醒）
        $scheduled = 0;
        foreach ($items as $i) {
            $due = $i['due'] ?? null;
            if (! $due) {
                continue;
            }
            try {
                $at = \Illuminate\Support\Carbon::parse((string) $due, 'Asia/Taipei');
                if ($at->isFuture()) {
                    ScheduledTask::create([
                        'command' => '提醒我（會議待辦）：'.$i['task'],
                        'run_at' => $at->utc(), 'status' => 'pending',
                    ]);
                    $scheduled++;
                }
            } catch (Throwable) {
            }
        }

        $doc = "📋 {$title}\n（".$m->started_at->timezone('Asia/Taipei')->format('m/d H:i').'～'.now('Asia/Taipei')->format('H:i')."）\n\n"
            ."【重點摘要】\n{$summary}\n"
            .($decisions->isNotEmpty() ? "\n【決議】\n".$decisions->map(fn ($d) => "・{$d}")->implode("\n")."\n" : '')
            .($items->isNotEmpty() ? "\n【待辦】\n".$items->map(fn ($i) => '・'.$i['task'].(($i['due'] ?? null) ? "（{$i['due']}）" : ''))->implode("\n") : '')
            .($scheduled > 0 ? "\n\n⏰ 已把 {$scheduled} 個有期限的待辦建成定時提醒。" : '');

        $m->summary = $doc;
        $m->status = 'done';
        $m->ended_at = $m->ended_at ?? now();
        $m->save();

        try {
            $notifier->send($doc);
        } catch (Throwable) {
        }
        if (($node = ReverseBus::ownerPhoneNode($m->user_id)) !== null) {
            try {
                ReverseBus::fire($node, 'show_document', ['title' => $title, 'content' => $doc]);
                ReverseBus::fire($node, 'phone_speak', ['text' => "會議記錄整理好了：{$title}。重點和待辦已經推給你，"
                    .($scheduled > 0 ? "其中 {$scheduled} 個有期限的待辦我已排好提醒。" : '')]);
            } catch (Throwable) {
            }
        }
    }
}
