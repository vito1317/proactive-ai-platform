<?php

namespace App\Pai\Integrations;

use App\Pai\Settings\Settings;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Gmail 整合（免 OAuth，用「應用程式密碼」）：讀未讀信（IMAP）+ 寄信（SMTP）。
 * 設定鍵：mail.address（你的 Gmail）、mail.app_password（Google 應用程式密碼）。
 * host 預設 imap.gmail.com:993 / smtp.gmail.com:587，可用 mail.imap_host / mail.smtp_host 覆寫。
 */
class Mailer
{
    private ?int $uid = null;   // 指定帳號（per-user 設定優先，無則落回全域）

    public function __construct(private readonly Settings $settings) {}

    /** 取指定帳號的 Mailer（收件匣助理多帳號用）。 */
    public function forUser(?int $uid): self
    {
        $c = clone $this;
        $c->uid = $uid;

        return $c;
    }

    public function configured(): bool
    {
        return (bool) ($this->settings->get('mail.address', null, $this->uid) && $this->settings->get('mail.app_password', null, $this->uid));
    }

    private function cred(): array
    {
        return [
            (string) $this->settings->get('mail.address', null, $this->uid),
            (string) $this->settings->get('mail.app_password', null, $this->uid),
        ];
    }

    /** 讀未讀信摘要（寄件者＋主旨），最多 $limit 封。 */
    public function unread(int $limit = 8): array
    {
        if (! $this->configured() || ! function_exists('imap_open')) {
            return ['ok' => false, 'error' => '未設定 Gmail（mail.address / mail.app_password）或缺 imap 擴充'];
        }
        [$user, $pass] = $this->cred();
        $host = (string) ($this->settings->get('mail.imap_host') ?: '{imap.gmail.com:993/imap/ssl}INBOX');
        try {
            $mbox = @imap_open($host, $user, $pass, 0, 1);
            if (! $mbox) {
                return ['ok' => false, 'error' => 'IMAP 登入失敗：'.imap_last_error()];
            }
            $ids = imap_search($mbox, 'UNSEEN') ?: [];
            rsort($ids);
            $items = [];
            foreach (array_slice($ids, 0, $limit) as $id) {
                $h = imap_headerinfo($mbox, $id);
                $from = isset($h->from[0]) ? ($h->from[0]->personal ?? $h->from[0]->mailbox.'@'.$h->from[0]->host) : '?';
                $subj = isset($h->subject) ? $this->decode($h->subject) : '(無主旨)';
                $items[] = ['from' => $this->decode((string) $from), 'subject' => $subj,
                    'date' => isset($h->udate) ? date('m/d H:i', $h->udate) : ''];
            }
            imap_close($mbox);

            return ['ok' => true, 'count' => count($ids), 'items' => $items];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 讀未讀信「完整內容」（message_id/寄件人/內文節錄）。用 FT_PEEK 讀，不會把信標成已讀。
     * 給收件匣助理分類＋擬稿用。
     */
    public function unreadFull(int $limit = 5): array
    {
        if (! $this->configured() || ! function_exists('imap_open')) {
            return ['ok' => false, 'error' => '未設定 Gmail 或缺 imap 擴充'];
        }
        [$user, $pass] = $this->cred();
        $host = (string) ($this->settings->get('mail.imap_host', null, $this->uid) ?: '{imap.gmail.com:993/imap/ssl}INBOX');
        try {
            $mbox = @imap_open($host, $user, $pass, 0, 1);
            if (! $mbox) {
                return ['ok' => false, 'error' => 'IMAP 登入失敗：'.imap_last_error()];
            }
            $ids = imap_search($mbox, 'UNSEEN') ?: [];
            rsort($ids);
            $items = [];
            foreach (array_slice($ids, 0, $limit) as $id) {
                $h = imap_headerinfo($mbox, $id);
                $fromName = isset($h->from[0]) ? $this->decode((string) ($h->from[0]->personal ?? $h->from[0]->mailbox)) : '?';
                $fromMail = isset($h->from[0]) ? $h->from[0]->mailbox.'@'.$h->from[0]->host : '';
                $body = '';
                foreach (['1.1', '1', '2'] as $sec) { // 常見 text part 位置
                    $raw = @imap_fetchbody($mbox, $id, $sec, FT_PEEK);
                    if (is_string($raw) && trim($raw) !== '') {
                        $body = $raw;
                        break;
                    }
                }
                // best-effort 解編碼：base64（整段合法且解出合法 UTF-8）→ quoted-printable → 去 HTML
                $t = trim($body);
                if (strlen($t) > 100 && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $t)) {
                    $d = base64_decode($t, true);
                    if ($d !== false && mb_check_encoding($d, 'UTF-8')) {
                        $body = $d;
                    }
                }
                $body = trim((string) preg_replace('/\s{3,}/', ' ', strip_tags(quoted_printable_decode($body))));
                $items[] = [
                    'msg_id' => trim((string) ($h->message_id ?? $id)),
                    'from_name' => $fromName, 'from_email' => $fromMail,
                    'subject' => isset($h->subject) ? $this->decode($h->subject) : '(無主旨)',
                    'body' => mb_substr($body, 0, 1500),
                    'date' => isset($h->udate) ? date('m/d H:i', $h->udate) : '',
                ];
            }
            imap_close($mbox);

            return ['ok' => true, 'count' => count($ids), 'items' => $items];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** 寄信。 */
    public function send(string $to, string $subject, string $body): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => '未設定 Gmail（mail.address / mail.app_password）'];
        }
        [$user, $pass] = $this->cred();
        $host = (string) ($this->settings->get('mail.smtp_host', null, $this->uid) ?: 'smtp.gmail.com');
        try {
            $transport = new EsmtpTransport($host, 587, false);
            $transport->setUsername($user);
            $transport->setPassword($pass);
            $mailer = new \Symfony\Component\Mailer\Mailer($transport);
            $email = (new Email())->from($user)->to($to)->subject($subject)->text($body);
            $mailer->send($email);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function decode(string $s): string
    {
        try {
            $out = '';
            foreach (imap_mime_header_decode($s) as $p) {
                $cs = strtolower($p->charset);
                $out .= ($cs === 'default' || $cs === 'utf-8') ? $p->text : mb_convert_encoding($p->text, 'UTF-8', $p->charset);
            }

            return $out;
        } catch (Throwable) {
            return $s;
        }
    }
}
