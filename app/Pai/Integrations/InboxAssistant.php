<?php

namespace App\Pai\Integrations;

use App\Pai\Cognition\LlmClient;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 收件匣助理：每 5 分鐘掃新的未讀信 → LLM 分類＋擬回覆草稿。
 *   重要/需回覆 → 立刻通知（含摘要＋草稿），說「好，寄出」或按按鈕就寄；
 *   一般 → 累積成每小時一則摘要；廣告/通知信 → 靜音（只計數，週報看得到）。
 * 讀信用 FT_PEEK，不會動 Gmail 的未讀狀態。開關：inbox.assistant_enabled（預設關）。
 */
class InboxAssistant
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly LlmClient $llm,
        private readonly Notifier $notifier,
        private readonly Settings $settings,
    ) {}

    /** 排程每 5 分鐘：掃所有開啟助理且設好 Gmail 的帳號。 */
    public function scan(): void
    {
        foreach (\App\Models\User::pluck('id') as $uid) {
            try {
                $this->scanUser((int) $uid);
            } catch (Throwable $e) {
                Log::warning('收件匣助理掃描失敗', ['uid' => $uid, 'error' => $e->getMessage()]);
            }
        }
    }

    private function scanUser(int $uid): void
    {
        if (! (bool) $this->settings->get('inbox.assistant_enabled', false, $uid)) {
            return;
        }
        $m = $this->mailer->forUser($uid);
        if (! $m->configured()) {
            return;
        }
        $r = $m->unreadFull(5);
        if (empty($r['ok'])) {
            return;
        }
        $seen = (array) Cache::get("mail:seen:{$uid}", []);
        \App\Pai\Agent\Tenant::set($uid);
        foreach ((array) $r['items'] as $mail) {
            $mid = (string) ($mail['msg_id'] ?? '');
            if ($mid === '' || in_array($mid, $seen, true)) {
                continue;
            }
            $seen[] = $mid;
            $this->triage($uid, $mail);
        }
        Cache::put("mail:seen:{$uid}", array_slice($seen, -200), 86400 * 7);
    }

    /** LLM 分級：urgent 立刻通知＋草稿；normal 進小時摘要；low 靜音計數。 */
    private function triage(int $uid, array $mail): void
    {
        try {
            $v = $this->llm->chatJson([
                ['role' => 'system', 'content' => '你是收件匣助理。判斷這封信對收件人的重要性並輸出 JSON：'
                    .'{"importance":"urgent|normal|low","summary":"一句話摘要","needs_reply":true|false,"draft":"若需回覆，擬一封簡短得體的中文回覆草稿（純內文）"}。'
                    .'urgent=需要今天處理/真人寫給他的重要事（主管/客戶/家人、帳務異常、時效通知）；'
                    .'low=廣告、電子報、系統通知、行銷；其餘 normal。台灣正體中文。只輸出 JSON。'],
                ['role' => 'user', 'content' => "寄件人：{$mail['from_name']} <{$mail['from_email']}>\n主旨：{$mail['subject']}\n\n內文：\n{$mail['body']}"],
            ], ['max_tokens' => 400]);
        } catch (Throwable) {
            return; // 分類失敗 → 這封先跳過（之後仍在未讀，晨間簡報會提）
        }
        $imp = (string) ($v['importance'] ?? 'normal');
        $summary = trim((string) ($v['summary'] ?? '')) ?: $mail['subject'];

        if ($imp === 'low') {
            $key = 'mail:muted:'.$uid.':'.now('Asia/Taipei')->format('Y-m-d');
            Cache::add($key, 0, 86400 * 8); // database driver 的 increment 不會自動建 key
            Cache::increment($key);

            return;
        }
        if ($imp === 'urgent' || ! empty($v['needs_reply'])) {
            $draft = trim((string) ($v['draft'] ?? ''));
            $text = "📬 重要來信｜{$mail['from_name']}：{$mail['subject']}\n{$summary}";
            $actions = [];
            if ($draft !== '' && ! empty($v['needs_reply']) && $mail['from_email'] !== '') {
                Cache::put("mail:draft:{$uid}", [
                    'to' => $mail['from_email'],
                    'subject' => 'Re: '.$mail['subject'],
                    'body' => $draft,
                ], 3600);
                Cache::put("voice:pendingq:{$uid}", ['kind' => 'mail'], 1800); // 語音「好，寄出/不用」
                $text .= "\n\n✍️ 擬好的回覆草稿：\n".mb_substr($draft, 0, 400)."\n\n說「好，寄出」就回信；「不用」放棄。";
                $actions = [
                    ['label' => '✉️ 寄出草稿', 'path' => '/api/mail/decide', 'body' => ['decision' => 'send']],
                    ['label' => '✖ 不用', 'path' => '/api/mail/decide', 'body' => ['decision' => 'discard']],
                ];
            }
            try {
                $this->notifier->send($text, $actions);
            } catch (Throwable) {
            }

            return;
        }
        // normal → 進每小時摘要
        $key = "mail:digest:{$uid}";
        $list = (array) Cache::get($key, []);
        $list[] = "・{$mail['from_name']}：{$mail['subject']}（{$summary}）";
        Cache::put($key, array_slice($list, -20), 7200);
    }

    /** 排程每小時：把累積的一般信件榨成一則摘要。 */
    public function flushDigests(): void
    {
        foreach (\App\Models\User::pluck('id') as $uid) {
            $list = Cache::pull("mail:digest:{$uid}");
            if (! is_array($list) || $list === []) {
                continue;
            }
            try {
                \App\Pai\Agent\Tenant::set((int) $uid);
                $this->notifier->send("📥 過去一小時的一般來信（".count($list)." 封）：\n".implode("\n", $list));
            } catch (Throwable) {
            }
        }
    }

    /** 使用者決定：寄出草稿 / 放棄。回覆給使用者聽的話。 */
    public function decide(int $uid, bool $send): string
    {
        $draft = Cache::pull("mail:draft:{$uid}");
        Cache::forget("voice:pendingq:{$uid}");
        if (! is_array($draft)) {
            return '目前沒有等待寄出的回信草稿。';
        }
        if (! $send) {
            return '好，這封先不回。';
        }
        $r = $this->mailer->forUser($uid)->send((string) $draft['to'], (string) $draft['subject'], (string) $draft['body']);

        return ! empty($r['ok']) ? "✉️ 回信已寄給 {$draft['to']}。" : '寄信失敗：'.(string) ($r['error'] ?? '未知錯誤');
    }
}
