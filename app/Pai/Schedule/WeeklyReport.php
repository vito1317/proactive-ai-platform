<?php

namespace App\Pai\Schedule;

use App\Pai\Automation\Automation;
use App\Pai\Watch\WatchTask;
use Illuminate\Support\Facades\DB;

/**
 * AI 週報：「我這週幫你省了多少時間」。
 * 彙整本週的自動化觸發/守望盯梢/代打電話/記帳/新記憶/LLM 用量，
 * 用透明的估算公式換算省下的分鐘數。每週日 20:00 自動推，也可語音隨時問。
 */
class WeeklyReport
{
    /** 估算每類事情幫使用者省的分鐘數（透明公式，寧可保守）。 */
    private const MIN_PER = ['watch_hit' => 10, 'call' => 8, 'auto_fired' => 3, 'task_done' => 2];

    public static function build(int $uid): string
    {
        $from = now('Asia/Taipei')->startOfWeek()->utc();
        $to = now()->utc();

        $autos = Automation::where('user_id', $uid)->get();
        $autoEnabled = $autos->where('enabled', true)->count();
        $autoFired = $autos->whereNotNull('last_run_at')->where('last_run_at', '>=', $from)->count();

        $watches = WatchTask::where('user_id', $uid)->where('updated_at', '>=', $from)
            ->whereIn('status', ['hit', 'expired', 'cancelled', 'error'])->get();
        $watchHit = $watches->where('status', 'hit')->count();

        $calls = DB::table('outbound_calls')->where('user_id', $uid)->where('created_at', '>=', $from)->get();
        $callDone = $calls->where('status', 'completed')->count();

        $tasksDone = DB::table('scheduled_tasks')->where('status', 'done')->where('updated_at', '>=', $from)->count();

        $exp = DB::table('expenses')->where('user_id', $uid)->where('spent_at', '>=', $from);
        $expCount = (int) $exp->count();
        $expTotal = (float) $exp->sum('amount');

        $memories = (int) DB::table('user_memories')->where('user_id', $uid)->where('created_at', '>=', $from)->count();

        $llm = DB::table('llm_usages')->where('day', '>=', now('Asia/Taipei')->startOfWeek()->format('Y-m-d'))
            ->selectRaw('COALESCE(SUM(calls),0) c, COALESCE(SUM(prompt_tokens)+SUM(completion_tokens),0) t')->first();

        $saved = $watchHit * self::MIN_PER['watch_hit'] + $callDone * self::MIN_PER['call']
            + $autoFired * self::MIN_PER['auto_fired'] + $tasksDone * self::MIN_PER['task_done'];

        $lines = ["📈 本週 AI 週報（".now('Asia/Taipei')->startOfWeek()->format('m/d').'～'.now('Asia/Taipei')->format('m/d')."）"];
        $lines[] = "・自動化：{$autoEnabled} 條啟用中，本週觸發過 {$autoFired} 條（含已自動停止的）";
        if ($watches->count() > 0) {
            $lines[] = "・守望/盯梢：完成 {$watches->count()} 個（幫你盯到 {$watchHit} 次）";
        }
        if ($calls->count() > 0) {
            $lines[] = "・代打電話：{$calls->count()} 通（講成 {$callDone} 通）";
        }
        if ($tasksDone > 0) {
            $lines[] = "・定時任務：完成 {$tasksDone} 個";
        }
        if ($expCount > 0) {
            $lines[] = "・記帳：{$expCount} 筆、共 $".number_format($expTotal);
        }
        if ($memories > 0) {
            $lines[] = "・新學到的長期記憶：{$memories} 條";
        }
        $lines[] = '・AI 運算：'.number_format((int) ($llm->c ?? 0)).' 次呼叫、'.number_format((int) ($llm->t ?? 0)).' tokens';
        $lines[] = $saved > 0
            ? "⏱ 估計幫你省下約 {$saved} 分鐘（盯梢命中×10、代打電話×8、自動化觸發×3、定時任務×2）"
            : '⏱ 本週還沒有可估算的省時項目——多丟點事給我吧！';

        return implode("\n", $lines);
    }
}
