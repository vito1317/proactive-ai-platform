<?php

namespace App\Console\Commands;

use App\Pai\Security\SecretRef;
use App\Pai\Security\SecretVault;
use Illuminate\Console\Command;

/**
 * 管理零信任金庫。智能體只會看到佔位符，永遠拿不到明文。
 * 用法：
 *   php artisan pai:secret set siem_token s3cr3t
 *   php artisan pai:secret list
 *   php artisan pai:secret forget siem_token
 */
class PaiSecretCommand extends Command
{
    protected $signature = 'pai:secret {action : set|list|forget} {name?} {value?}';

    protected $description = '管理零信任機密金庫';

    public function handle(SecretVault $vault): int
    {
        $action = $this->argument('action');
        $name = $this->argument('name');

        return match ($action) {
            'set' => $this->set($vault, $name),
            'list' => $this->list($vault),
            'forget' => $this->forget($vault, $name),
            default => $this->bail("未知動作 {$action}"),
        };
    }

    private function set(SecretVault $vault, ?string $name): int
    {
        if (! $name) {
            return $this->bail('需要 name');
        }
        $value = $this->argument('value') ?? $this->secret('輸入密鑰值');
        $vault->put($name, (string) $value);
        $this->info("已存入 [{$name}]。智能體使用佔位符：".SecretRef::placeholder($name));

        return self::SUCCESS;
    }

    private function list(SecretVault $vault): int
    {
        $names = $vault->names();
        if ($names === []) {
            $this->warn('金庫為空。');

            return self::SUCCESS;
        }
        $this->info('金庫內密鑰（僅名稱，值不外露）：');
        foreach ($names as $n) {
            $this->line('  • '.$n.'  →  '.SecretRef::placeholder($n));
        }

        return self::SUCCESS;
    }

    private function forget(SecretVault $vault, ?string $name): int
    {
        if (! $name) {
            return $this->bail('需要 name');
        }
        $vault->forget($name);
        $this->info("已刪除 [{$name}]。");

        return self::SUCCESS;
    }

    private function bail(string $msg): int
    {
        $this->error($msg);

        return self::FAILURE;
    }
}
