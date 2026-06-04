<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use Throwable;

/** 寫入 / 覆寫伺服器上的檔案。高風險。 */
class WriteFileSkill implements Skill
{
    public function name(): string
    {
        return 'write-file';
    }

    public function description(): string
    {
        return '建立或覆寫伺服器上的檔案內容（可選擇附加模式）';
    }

    public function parameters(): array
    {
        return [
            'path' => '檔案絕對路徑',
            'content' => '要寫入的內容',
            'append' => 'true 則附加在檔尾，否則覆寫（預設覆寫）',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        if ($path === '') {
            return '請提供 path。';
        }
        $content = (string) ($args['content'] ?? '');
        $append = filter_var($args['append'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return "無法建立目錄：{$dir}";
        }
        try {
            $bytes = file_put_contents($path, $content, $append ? FILE_APPEND : 0);
        } catch (Throwable $e) {
            return "寫入失敗：{$e->getMessage()}";
        }
        if ($bytes === false) {
            return "寫入失敗（權限不足？）：{$path}";
        }

        return ($append ? '已附加' : '已寫入')." {$bytes} bytes 至 {$path} ✅。";
    }
}
