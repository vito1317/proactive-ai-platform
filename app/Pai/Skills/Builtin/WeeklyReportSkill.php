<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Schedule\WeeklyReport;
use App\Pai\Skills\Skill;

/** 隨時查 AI 週報：「你這週幫我做了什麼/省了多少時間/給我週報」。 */
class WeeklyReportSkill implements Skill
{
    public function name(): string
    {
        return 'weekly-report';
    }

    public function description(): string
    {
        return 'AI 週報：本週自動化觸發/守望盯梢/代打電話/記帳/新記憶統計＋估計省下的時間。'
            .'使用者問「你這週幫我做了什麼」「省了多少時間」「給我週報」時用。';
    }

    public function parameters(): array
    {
        return [];
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

        return WeeklyReport::build($uid);
    }
}
