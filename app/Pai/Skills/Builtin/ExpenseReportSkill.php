<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\DB;

/** 消費查詢/月結：「這個月花多少」「上個月餐飲花多少」。 */
class ExpenseReportSkill implements Skill
{
    public function name(): string
    {
        return 'expense-report';
    }

    public function description(): string
    {
        return '查詢消費/支出統計。period=this_month|last_month|this_week|today（預設 this_month）；category 選填（只統計該分類）。'
            .'使用者問「這個月花多少」「今天花了什麼」時用。';
    }

    public function parameters(): array
    {
        return [
            'period' => '（選填）this_month|last_month|this_week|today，預設 this_month',
            'category' => '（選填）分類過濾',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $uid = Tenant::id();
        if ($uid === null) {
            return '無法判斷帳號。';
        }
        $now = now('Asia/Taipei');
        [$from, $to, $label] = match ((string) ($args['period'] ?? 'this_month')) {
            'last_month' => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth(), '上個月'],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), '這週'],
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), '今天'],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), '這個月'],
        };
        return self::summarize($uid, $from->utc(), $to->utc(), $label, trim((string) ($args['category'] ?? '')) ?: null);
    }

    /** 給技能與每月自動摘要共用。 */
    public static function summarize(int $uid, $fromUtc, $toUtc, string $label, ?string $category = null): string
    {
        $q = DB::table('expenses')->where('user_id', $uid)->whereBetween('spent_at', [$fromUtc, $toUtc]);
        if ($category !== null) {
            $q->where('category', 'like', "%{$category}%");
        }
        $rows = $q->orderByDesc('spent_at')->get();
        if ($rows->isEmpty()) {
            return "{$label}".($category ? "「{$category}」" : '').'沒有記帳紀錄。';
        }
        $total = $rows->sum('amount');
        $byCat = $rows->groupBy(fn ($r) => $r->category ?: '未分類')
            ->map(fn ($g) => $g->sum('amount'))->sortDesc()
            ->map(fn ($v, $k) => "{$k} $".number_format((float) $v))->implode('、');
        $top = $rows->sortByDesc('amount')->take(3)
            ->map(fn ($r) => "{$r->item} $".number_format((float) $r->amount))->implode('、');

        return "💰 {$label}共 {$rows->count()} 筆、合計 $".number_format((float) $total)
            ."。\n分類：{$byCat}。\n最大筆：{$top}。";
    }
}
