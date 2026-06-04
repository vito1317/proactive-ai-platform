<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Throwable;

/** 精確字串取代（old → new，類似 IDE 的 str-replace/Edit）。高風險。 */
class EditFileSkill implements Skill
{
    public function name(): string
    {
        return 'edit-file';
    }

    public function description(): string
    {
        return '在檔案中把一段「舊文字」精確取代成「新文字」（須唯一匹配；用於改設定/程式碼，比覆寫整檔安全）';
    }

    public function parameters(): array
    {
        return [
            'path' => '檔案絕對路徑',
            'old' => '要被取代的原文字（需與檔內完全相符）',
            'new' => '取代後的新文字',
            'all' => 'true 則取代所有出現處（預設僅在唯一匹配時取代）',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        $old = (string) ($args['old'] ?? '');
        $new = (string) ($args['new'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return "找不到檔案：{$path}";
        }
        if ($old === '') {
            return '請提供要取代的 old 文字（避免誤改整檔）。';
        }
        $content = (string) file_get_contents($path);
        $count = substr_count($content, $old);
        if ($count === 0) {
            return "在 {$path} 找不到要取代的文字（請確認與檔內完全相符，含空白與縮排）。";
        }
        $all = filter_var($args['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($count > 1 && ! $all) {
            return "該文字在 {$path} 出現 {$count} 次（非唯一）。請提供更完整的上下文以唯一匹配，或設 all=true 全部取代。";
        }
        $updated = $all ? str_replace($old, $new, $content) : preg_replace('/'.preg_quote($old, '/').'/', addcslashes($new, '\\$'), $content, 1);

        try {
            file_put_contents($path, $updated);
        } catch (Throwable $e) {
            return "寫入失敗：{$e->getMessage()}";
        }

        return "已在 {$path} 取代 ".($all ? $count : 1).' 處 ✅。';
    }
}
