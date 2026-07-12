<?php

namespace App\Pai\Automation;

use Illuminate\Support\Facades\DB;

/**
 * 習慣挖掘：從「真實事件流」統計使用者的重複行為，餵給 ProactiveBrain——
 * 讓它根據實際習慣（「你每週五晚上都查高鐵」）提議自動化，而不是憑記憶想像。
 * 資料源：使用者對話指令（近30天）、完成的定時任務、感知事件（pai_events）。
 */
class HabitMiner
{
    /** 產出行為統計摘要（給 LLM 的文字塊）。沒有足夠資料回空字串。 */
    public static function digest(int $uid): string
    {
        $lines = [];

        // 1) 重複出現的口語指令（同開頭聚類，出現 ≥3 次）＋慣用時段
        try {
            $msgs = DB::table('conversation_messages')
                ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
                ->where('conversations.user_id', $uid)
                ->where('conversation_messages.role', 'user')
                ->where('conversation_messages.created_at', '>=', now()->subDays(30))
                ->orderByDesc('conversation_messages.id')->limit(600)
                ->get(['conversation_messages.content', 'conversation_messages.created_at']);
            $groups = [];
            foreach ($msgs as $m) {
                $key = mb_substr((string) preg_replace('/[\d\s，。,\.!！?？:：;；]/u', '', (string) $m->content), 0, 10);
                if (mb_strlen($key) < 3) {
                    continue;
                }
                $at = \Illuminate\Support\Carbon::parse($m->created_at)->timezone('Asia/Taipei');
                $groups[$key][] = [$at->isoWeekday(), (int) $at->format('H')];
            }
            $tops = collect($groups)->filter(fn ($v) => count($v) >= 3)->sortByDesc(fn ($v) => count($v))->take(8);
            foreach ($tops as $key => $hits) {
                $dows = array_count_values(array_column($hits, 0));
                arsort($dows);
                $dow = array_key_first($dows);
                $hours = array_column($hits, 1);
                sort($hours);
                $h = $hours[intdiv(count($hours), 2)];
                $dowName = ['', '一', '二', '三', '四', '五', '六', '日'][$dow] ?? '?';
                $lines[] = "・「{$key}…」出現 ".count($hits)." 次（最常在週{$dowName}、約 {$h} 點）";
            }
        } catch (\Throwable) {
        }

        // 2) 完成過的定時任務（重複排的事＝潛在固定習慣）
        try {
            $tasks = DB::table('scheduled_tasks')->where('status', 'done')
                ->where('updated_at', '>=', now()->subDays(30))
                ->orderByDesc('id')->limit(100)->pluck('command');
            $tg = collect($tasks)->countBy(fn ($c) => mb_substr((string) preg_replace('/[\d\s]/u', '', (string) $c), 0, 10))
                ->filter(fn ($n) => $n >= 2)->sortDesc()->take(5);
            foreach ($tg as $key => $n) {
                $lines[] = "・定時任務「{$key}…」排過 {$n} 次（重複排＝可考慮固定自動化）";
            }
        } catch (\Throwable) {
        }

        // 3) 感知事件分布（哨兵/日誌等）
        try {
            $ev = DB::table('pai_events')->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('type, COUNT(*) n')->groupBy('type')->orderByDesc('n')->limit(5)->get();
            foreach ($ev as $e) {
                $lines[] = "・事件 {$e->type}：{$e->n} 次/30天";
            }
        } catch (\Throwable) {
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }
}
