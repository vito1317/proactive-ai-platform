<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Throwable;

/** 在指定行號後插入文字（0 = 檔首）。高風險。 */
class InsertInFileSkill implements Skill
{
    public function name(): string
    {
        return 'insert-in-file';
    }

    public function description(): string
    {
        return '在檔案的指定行號之後插入一段文字（line=0 表示插在檔首）';
    }

    public function parameters(): array
    {
        return [
            'path' => '檔案絕對路徑',
            'line' => '要在第幾行之後插入（0 = 檔首）',
            'text' => '要插入的文字',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return "找不到檔案：{$path}";
        }
        $text = (string) ($args['text'] ?? '');
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $at = max(0, min(count($lines), (int) ($args['line'] ?? count($lines))));
        array_splice($lines, $at, 0, preg_split('/\r?\n/', $text));

        try {
            file_put_contents($path, implode("\n", $lines)."\n");
        } catch (Throwable $e) {
            return "寫入失敗：{$e->getMessage()}";
        }

        return "已在 {$path} 第 {$at} 行後插入內容 ✅。";
    }
}
