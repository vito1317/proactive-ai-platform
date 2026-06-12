<?php

namespace App\Pai\Commute;

use App\Pai\Settings\Settings;

/**
 * 名稱對應簿：把「人名」對應到「平台 + 收件目標」，讓提醒要傳訊息時知道發給誰、用哪個平台。
 * 設定 contacts.map 每行一筆：「名稱=平台:目標」，平台 = line|telegram|sms。
 *   例：Rex Chang=line:Uxxxxxxxx
 *       媽=sms:0912345678
 * 找不到對應時用 notify.default_platform 當預設平台（交給 agent 操作該平台找人）。
 */
class ContactBook
{
    public function __construct(private readonly Settings $settings) {}

    /** 解析人名 → ['platform'=>, 'target'=>]；找不到回 null。 */
    public function resolve(int $uid, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        foreach (preg_split('/\r?\n/', (string) $this->settings->get('contacts.map', '', $uid)) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$n, $rest] = array_map('trim', explode('=', $line, 2));
            if ($n === '' || mb_strtolower($n) !== mb_strtolower($name)) {
                continue;
            }
            // rest = 平台:目標（沒冒號就當成預設平台 + 目標）
            if (str_contains($rest, ':')) {
                [$platform, $target] = array_map('trim', explode(':', $rest, 2));
            } else {
                $platform = $this->defaultPlatform($uid);
                $target = trim($rest);
            }

            return ['platform' => mb_strtolower($platform) ?: $this->defaultPlatform($uid), 'target' => $target];
        }

        return null;
    }

    /** 預設發送平台（line|telegram|sms|agent）。 */
    public function defaultPlatform(int $uid): string
    {
        $p = mb_strtolower(trim((string) $this->settings->get('notify.default_platform', 'agent', $uid)));

        return in_array($p, ['line', 'telegram', 'sms', 'agent'], true) ? $p : 'agent';
    }
}
