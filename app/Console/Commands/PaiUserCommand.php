<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * 建立 / 更新中控台使用者。
 * 用法：php artisan pai:user vito_ke@intellitrustme.com [密碼]
 */
class PaiUserCommand extends Command
{
    protected $signature = 'pai:user {email} {password?} {--name=管理員}';

    protected $description = '建立或更新中控台登入使用者';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password') ?: $this->secret('設定密碼');
        if (! $password) {
            $this->error('需要密碼。');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $this->option('name'), 'password' => Hash::make($password)],
        );

        $this->info("使用者已就緒：{$user->email}（id={$user->id}）");

        return self::SUCCESS;
    }
}
