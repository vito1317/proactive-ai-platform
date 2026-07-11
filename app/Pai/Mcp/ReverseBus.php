<?php

namespace App\Pai\Mcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 反向節點匯流排（給無公網/無法被連入的節點，如 Android 手機用）。
 *
 * 流程：
 *  - PAI 要呼叫工具 → call() 把請求放佇列、阻塞輪詢結果。
 *  - 節點（手機）long-poll next() 取出待執行請求 → 本地執行 → submit() 回傳結果。
 * 用 Cache 當佇列/結果暫存（單機 file/redis 皆可），不需 WebSocket server。
 */
class ReverseBus
{
    private const PENDING = 'gwrev:pending:';   // 佇列（待手機取走的 call）
    private const RESULT = 'gwrev:result:';     // 結果（手機回傳）
    private const TOOLS = 'gwrev:tools:';       // 節點工具清單（註冊時帶上）
    private const SEEN = 'gwrev:seen:';         // 節點最後上線時間

    /** PAI 端：發一個工具呼叫給反向節點，等結果（預設最多 60 秒）。 */
    public static function call(string $node, string $tool, array $args, int $timeoutSec = 90): array
    {
        $id = (string) Str::uuid();
        $queue = Cache::get(self::PENDING.$node, []);
        $queue[] = ['id' => $id, 'tool' => $tool, 'arguments' => $args];
        Cache::put(self::PENDING.$node, $queue, 120);

        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $r = Cache::pull(self::RESULT.$id);
            if ($r !== null) {
                return is_array($r) && isset($r['ok'])
                    ? $r
                    : ['ok' => true, 'text' => (string) $r];
            }
            usleep(300_000); // 0.3s
        }

        return ['ok' => false, 'error' => '反向節點逾時未回應（手機可能離線或 App 未在前景）'];
    }

    /** 發一個工具呼叫但不等結果（fire-and-forget，用於推通知等不需回傳的動作）。 */
    public static function fire(string $node, string $tool, array $args): void
    {
        $queue = Cache::get(self::PENDING.$node, []);
        $queue[] = ['id' => (string) Str::uuid(), 'tool' => $tool, 'arguments' => $args];
        Cache::put(self::PENDING.$node, $queue, 120);
    }

    /** 找某帳號「在線」的手機反向節點（優先非電腦類名稱），沒有則 null。 */
    public static function ownerPhoneNode(int $uid): ?string
    {
        try {
            $owned = McpServer::where('user_id', $uid)->where('url', 'like', 'reverse://%')->pluck('name')->all();
            $online = array_values(array_filter(self::onlineNodes(), fn ($n) => in_array($n, $owned, true)));
            $phones = array_values(array_filter($online, fn ($n) => ! preg_match('/mac|macbook|imac|air|pc|desktop|windows|linux|laptop/i', $n)));

            return $phones[0] ?? $online[0] ?? null;
        } catch (\Throwable) {
        }

        return null;
    }

    /** 列出目前所有「在線」的反向節點名稱（最近 5 分鐘有 poll）。 */
    public static function onlineNodes(): array
    {
        return McpServer::where('url', 'like', 'reverse://%')->get()
            ->filter(fn ($s) => self::lastSeen($s->name) !== null)
            ->pluck('name')->all();
    }

    /** 節點端 long-poll：取出一個待執行的 call（最多等 $waitSec）。回 null 表示沒有。 */
    public static function next(string $node, int $waitSec = 25): ?array
    {
        self::touch($node);
        $deadline = microtime(true) + $waitSec;
        do {
            $queue = Cache::get(self::PENDING.$node, []);
            if (! empty($queue)) {
                $call = array_shift($queue);
                Cache::put(self::PENDING.$node, $queue, 120);
                return $call;
            }
            usleep(400_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /** 節點端：回傳某 call 的執行結果。 */
    public static function submit(string $id, string $text): void
    {
        Cache::put(self::RESULT.$id, ['ok' => true, 'text' => $text], 120);
    }

    /** 註冊時存節點工具清單。 */
    public static function setTools(string $node, array $tools): void
    {
        Cache::put(self::TOOLS.$node, $tools, 86400 * 30);
        self::touch($node);
    }

    public static function tools(string $node): array
    {
        $t = Cache::get(self::TOOLS.$node);

        return is_array($t) ? ['ok' => true, 'tools' => $t] : ['ok' => false, 'error' => '反向節點尚未回報工具清單'];
    }

    public static function touch(string $node): void
    {
        Cache::put(self::SEEN.$node, time(), 300);
    }

    public static function lastSeen(string $node): ?int
    {
        return Cache::get(self::SEEN.$node);
    }
}
