<?php

namespace App\Pai\Chat;

use App\Pai\Settings\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 自訂斜線指令：/name [args] → 展開成指令內容（body）。
 * 聊天室 / TG / LINE 共用同一套定義；新增/移除時同步到 Telegram 的指令選單。
 */
class SlashCommands
{
    /** 內建、不可被自訂覆蓋的指令。 */
    private const RESERVED = ['new', 'start'];

    public function __construct(private readonly Settings $settings) {}

    /** 若訊息是已定義的自訂斜線指令，回傳展開後的內容；否則 null。 */
    public function expand(string $text): ?string
    {
        $t = ltrim(trim($text));
        if (! str_starts_with($t, '/')) {
            return null;
        }
        $parts = preg_split('/\s+/', substr($t, 1), 2);
        $name = strtolower(strtok($parts[0], '@')); // 兼容 TG 的 /cmd@bot
        $args = trim($parts[1] ?? '');
        if ($name === '' || in_array($name, self::RESERVED, true)) {
            return null;
        }
        $cmd = SlashCommand::where('name', $name)->where('enabled', true)->first();
        if (! $cmd) {
            return null;
        }

        return str_contains($cmd->body, '{{args}}')
            ? str_replace('{{args}}', $args, $cmd->body)
            : trim($cmd->body.($args !== '' ? "\n\n（附帶：{$args}）" : ''));
    }

    public function add(string $name, string $body, ?string $description = null): SlashCommand
    {
        $name = strtolower(ltrim(trim($name), '/'));
        $cmd = SlashCommand::updateOrCreate(['name' => $name], ['body' => $body, 'description' => $description, 'enabled' => true]);
        $this->syncTelegram();

        return $cmd;
    }

    public function remove(string $name): bool
    {
        $ok = (bool) SlashCommand::where('name', strtolower(ltrim(trim($name), '/')))->delete();
        $this->syncTelegram();

        return $ok;
    }

    /** @return Collection<int,SlashCommand> */
    public function all()
    {
        return SlashCommand::orderBy('name')->get();
    }

    /** 把自訂指令同步到 Telegram 的「/」指令選單（setMyCommands）。 */
    public function syncTelegram(): void
    {
        $token = $this->settings->get('notify.telegram.token');
        if (! $token) {
            return;
        }
        $commands = SlashCommand::where('enabled', true)->get()
            ->map(fn ($c) => ['command' => $c->name, 'description' => mb_substr($c->description ?: $c->name, 0, 256)])
            ->values()->all();
        // 內建指令也一起列出
        array_unshift($commands, ['command' => 'new', 'description' => '開新對話']);
        try {
            Http::timeout(8)->post("https://api.telegram.org/bot{$token}/setMyCommands", ['commands' => $commands]);
        } catch (Throwable) {
            // ignore
        }
    }
}
