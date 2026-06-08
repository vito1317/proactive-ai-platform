<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use App\Pai\Skills\SkillRegistry;

/**
 * 循環/輪詢工具：重複執行某個工具——
 *  - mode=times：連續跑 count 次（重複任務）。
 *  - mode=until：每隔 interval 秒跑一次，直到結果「出現指定文字」或「內容改變」才停（偵測狀態改變）。
 */
class LoopSkill implements Skill
{
    public function __construct(private readonly SkillRegistry $registry) {}

    public function name(): string
    {
        return 'loop';
    }

    public function description(): string
    {
        return '重複執行一個工具。mode=times：跑 count 次；mode=until：每隔 interval 秒跑一次直到結果出現 until 文字、或 detect_change=true 時直到內容改變（最多 max 次）。用於重複任務或偵測狀態改變（如等某元素出現、等檔案產生）';
    }

    public function parameters(): array
    {
        return [
            'tool' => '要重複執行的技能名（如 browser_read、screen_snapshot、run-shell…）',
            'args' => '該技能的參數（物件，選填）',
            'mode' => 'times（跑固定次數）或 until（重複到條件成立），預設 until',
            'count' => 'mode=times：執行幾次（1~10）',
            'interval' => 'mode=until：每次間隔秒數（1~30，預設 5）',
            'max' => 'mode=until：最多嘗試幾次（1~20，預設 10）',
            'until' => 'mode=until：結果中出現這段文字就停（選填）',
            'detect_change' => 'mode=until：true=結果和第一次不同就停（偵測狀態改變）',
        ];
    }

    public function isHighRisk(): bool
    {
        // 由它呼叫的子工具各自的風險閘門仍有效；loop 本身設高風險（可能反覆執行）
        return true;
    }

    public function run(array $args): string
    {
        $toolName = trim((string) ($args['tool'] ?? ''));
        if ($toolName === '') {
            return '請提供要重複執行的 tool（技能名）。';
        }
        $skill = $this->registry->get($toolName);
        if (! $skill) {
            return "找不到工具「{$toolName}」。";
        }
        $a = is_array($args['args'] ?? null) ? $args['args'] : [];
        $mode = ($args['mode'] ?? 'until') === 'times' ? 'times' : 'until';

        if ($mode === 'times') {
            $count = max(1, min(10, (int) ($args['count'] ?? 1)));
            $out = [];
            for ($i = 1; $i <= $count; $i++) {
                $out[] = "[$i/$count] ".mb_substr((string) $skill->run($a), 0, 400);
            }

            return "🔁 已重複執行 {$toolName} {$count} 次：\n".implode("\n", $out);
        }

        // mode=until：輪詢偵測
        $interval = max(1, min(30, (int) ($args['interval'] ?? 5)));
        $max = max(1, min(20, (int) ($args['max'] ?? 10)));
        $until = trim((string) ($args['until'] ?? ''));
        $detectChange = filter_var($args['detect_change'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $first = null;
        for ($i = 1; $i <= $max; $i++) {
            $r = (string) $skill->run($a);
            if ($first === null) {
                $first = $r;
            }
            if ($until !== '' && mb_stripos($r, $until) !== false) {
                return "✅ 第 {$i} 次偵測到「{$until}」：\n".mb_substr($r, 0, 600);
            }
            if ($detectChange && $i > 1 && $r !== $first) {
                return "✅ 第 {$i} 次偵測到狀態改變：\n".mb_substr($r, 0, 600);
            }
            if ($i < $max) {
                sleep($interval);
            }
        }

        return "⏱ 試了 {$max} 次仍未".($until !== '' ? "出現「{$until}」" : '改變')."。最後結果：\n".mb_substr((string) $first, 0, 600);
    }
}
