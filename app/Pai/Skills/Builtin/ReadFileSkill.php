<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;

/** 讀取伺服器上的檔案內容。低風險（唯讀）。 */
class ReadFileSkill implements Skill
{
    public function name(): string
    {
        return 'read-file';
    }

    public function description(): string
    {
        return '讀取伺服器上某個檔案的內容（用於查看設定、程式碼、資料）';
    }

    public function parameters(): array
    {
        return [
            'path' => '檔案絕對路徑',
            'max_chars' => '最多回傳字元數（預設 8000）',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return "找不到檔案：{$path}";
        }
        if (! is_readable($path)) {
            return "檔案無法讀取（權限不足）：{$path}";
        }
        $max = max(200, min(20000, (int) ($args['max_chars'] ?? 8000)));
        $size = filesize($path);
        $content = (string) file_get_contents($path, false, null, 0, $max + 1);
        $truncated = mb_strlen($content) > $max || $size > $max;

        return "檔案 {$path}（{$size} bytes）：\n".mb_substr($content, 0, $max)
            .($truncated ? "\n…（已截斷，使用 max_chars 或 run-shell 查看更多）" : '');
    }
}
