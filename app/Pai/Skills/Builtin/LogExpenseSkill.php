<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\DB;

/** 語音記帳：「剛剛午餐花 120」→ 存一筆支出。金額/品項由 LLM 從口語抽出後帶參數呼叫。 */
class LogExpenseSkill implements Skill
{
    public function name(): string
    {
        return 'log-expense';
    }

    public function description(): string
    {
        return '記一筆支出（記帳）。使用者說「剛剛午餐花120」「記帳：咖啡65」時用。'
            .'amount=金額數字；item=買什麼（午餐/咖啡/加油…）；category 選填（餐飲/交通/購物/娛樂/生活/醫療/其他，不確定就依 item 推斷）。';
    }

    public function parameters(): array
    {
        return [
            'amount' => '金額（數字）',
            'item' => '品項（買什麼）',
            'category' => '（選填）分類：餐飲/交通/購物/娛樂/生活/醫療/其他',
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
        $amount = (float) preg_replace('/[^\d.]/', '', (string) ($args['amount'] ?? ''));
        $item = trim((string) ($args['item'] ?? '')) ?: '未註明';
        if ($amount <= 0) {
            return '沒聽清楚金額，請再說一次（例：午餐花120）。';
        }
        DB::table('expenses')->insert([
            'user_id' => $uid, 'amount' => $amount, 'item' => $item,
            'category' => trim((string) ($args['category'] ?? '')) ?: null,
            'spent_at' => now(), 'source' => 'voice',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $monthTotal = (float) DB::table('expenses')->where('user_id', $uid)
            ->where('spent_at', '>=', now('Asia/Taipei')->startOfMonth()->utc())->sum('amount');

        return '💰 記好了：'.$item.' $'.number_format($amount).'。本月累計 $'.number_format($monthTotal).'。';
    }
}
