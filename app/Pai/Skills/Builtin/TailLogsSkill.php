<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;

/** 查看平台最近的錯誤日誌。低風險。 */
class TailLogsSkill implements Skill
{
    public function name(): string
    {
        return 'tail-logs';
    }

    public function description(): string
    {
        return '查看平台最近的應用日誌（laravel.log），用於排查問題';
    }

    public function parameters(): array
    {
        return ['lines' => '要看的行數（預設 40，上限 200）'];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $n = max(1, min(200, (int) ($args['lines'] ?? 40)));
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return '目前沒有日誌檔。';
        }
        $lines = array_slice(file($path, FILE_IGNORE_NEW_LINES) ?: [], -$n);

        return "最近 {$n} 行日誌：\n".implode("\n", array_map(fn ($l) => mb_substr($l, 0, 300), $lines));
    }
}
