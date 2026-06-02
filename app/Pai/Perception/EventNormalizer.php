<?php

namespace App\Pai\Perception;

/**
 * 把原始事件正規化成 intent + severity（OODA 的 Orient 前置）。
 *
 * 目前為規則式：依事件主題與負載關鍵字推斷。設計上預留 LLM seam——
 * 未來高度模糊的事件可改丟給 L3 大腦 (llama-server) 做語意分類，
 * 介面 {@see normalize()} 不變。負載若自帶 intent/severity 則優先採用。
 */
class EventNormalizer
{
    /** 主題前綴 → 意圖。 */
    private const INTENT_MAP = [
        'siem.alert' => 'security-alert',
        'edr.detection' => 'endpoint-threat',
        'cloudtrail.anomaly' => 'cloud-anomaly',
        'ci.failed' => 'test-failure',
        'pr.opened' => 'code-review',
        'git.push' => 'code-change',
        'issue.created' => 'task-intake',
        'log.error' => 'log-error',
    ];

    /** 出現於主題或負載即拉高嚴重性的關鍵字。 */
    private const HIGH_KEYWORDS = ['ransomware', 'breach', 'critical', 'exfiltration', 'malware', 'brute-force'];

    private const MEDIUM_KEYWORDS = ['alert', 'failed', 'anomaly', 'detection', 'error', 'denied'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{intent: string, severity: Severity}
     */
    public function normalize(string $topic, array $payload): array
    {
        return [
            'intent' => $this->inferIntent($topic, $payload),
            'severity' => $this->inferSeverity($topic, $payload),
        ];
    }

    private function inferIntent(string $topic, array $payload): string
    {
        if (isset($payload['intent']) && is_string($payload['intent']) && $payload['intent'] !== '') {
            return $payload['intent'];
        }

        if (isset(self::INTENT_MAP[$topic])) {
            return self::INTENT_MAP[$topic];
        }

        // fallback：取主題最後一段，如 "foo.bar.baz" → "baz"
        $parts = explode('.', $topic);

        return end($parts) ?: 'unknown';
    }

    private function inferSeverity(string $topic, array $payload): Severity
    {
        // 負載明確指定且合法 → 直接採用
        if (isset($payload['severity']) && is_string($payload['severity'])) {
            $sev = Severity::tryFrom(strtolower($payload['severity']));
            if ($sev !== null) {
                return $sev;
            }
        }

        $haystack = strtolower($topic.' '.json_encode($payload, JSON_UNESCAPED_UNICODE));

        foreach (self::HIGH_KEYWORDS as $kw) {
            if (str_contains($haystack, $kw)) {
                return Severity::High;
            }
        }
        foreach (self::MEDIUM_KEYWORDS as $kw) {
            if (str_contains($haystack, $kw)) {
                return Severity::Medium;
            }
        }

        return Severity::Low;
    }
}
