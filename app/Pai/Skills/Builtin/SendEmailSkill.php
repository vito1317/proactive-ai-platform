<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Integrations\Mailer;
use App\Pai\Skills\Skill;

/** 寄送 Gmail（用設定的信箱 + 應用程式密碼）。 */
class SendEmailSkill implements Skill
{
    public function __construct(private readonly Mailer $mailer) {}

    public function name(): string
    {
        return 'send-email';
    }

    public function description(): string
    {
        return '用 Gmail 寄一封信（需先設定 mail.address / mail.app_password）';
    }

    public function parameters(): array
    {
        return [
            'to' => '收件人 email',
            'subject' => '主旨',
            'body' => '內文',
        ];
    }

    public function isHighRisk(): bool
    {
        return true; // 對外寄信 → 受確認/自我修改閘門控管
    }

    public function run(array $args): string
    {
        $to = trim((string) ($args['to'] ?? ''));
        $subject = trim((string) ($args['subject'] ?? ''));
        $body = (string) ($args['body'] ?? '');
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return '請提供有效的收件人 email（to）。';
        }
        if ($subject === '' && $body === '') {
            return '請提供主旨或內文。';
        }
        $r = $this->mailer->send($to, $subject !== '' ? $subject : '(無主旨)', $body);

        return ($r['ok'] ?? false) ? "✅ 已寄信給 {$to}：{$subject}" : ('寄信失敗：'.($r['error'] ?? '未知').'（請確認已設定 mail.address / mail.app_password）');
    }
}
