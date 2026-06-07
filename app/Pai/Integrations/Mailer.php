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
    public function __construct(private readonly Settings $settings) {}

    public function configured(): bool
    {
        return (bool) ($this->settings->get('mail.address') && $this->settings->get('mail.app_password'));
    }

    private function cred(): array
    {
        return [
            (string) $this->settings->get('mail.address'),
            (string) $this->settings->get('mail.app_password'),
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

    /** 寄信。 */
    public function send(string $to, string $subject, string $body): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => '未設定 Gmail（mail.address / mail.app_password）'];
        }
        [$user, $pass] = $this->cred();
        $host = (string) ($this->settings->get('mail.smtp_host') ?: 'smtp.gmail.com');
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
