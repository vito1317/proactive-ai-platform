<?php

namespace App\Pai\Cognition;

use App\Pai\Domains\DomainRegistry;
use Throwable;

/**
 * 把使用者的自然語言指令對應到「領域 + 事件主題 + 嚴重性」。
 * 讓一般使用者不必懂 topic/JSON，直接用白話指揮 AI。
 */
class IntentClassifier
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly DomainRegistry $registry,
    ) {}

    /**
     * @return array{domain: ?string, topic: ?string, severity: string, rationale: string}
     */
    public function classify(string $message): array
    {
        $packs = $this->registry->all();
        if ($packs === []) {
            return ['domain' => null, 'topic' => null, 'severity' => 'low', 'rationale' => '尚無領域可路由'];
        }

        $catalog = [];
        foreach ($packs as $p) {
            $catalog[] = "- {$p->domain}（{$p->description}）topics: ".implode(', ', $p->eventTopics());
        }
        $catalogText = implode("\n", $catalog);

        $prompt = Prompts::render('intent-classifier', ['catalog' => $catalogText, 'message' => $message]);

        try {
            // 分類屬輕量任務：走小模型（若有設定）+ temperature 0 求穩定
            $out = $this->llm->chatJson([
                ['role' => 'user', 'content' => $prompt],
            ], ['tier' => 'small', 'temperature' => 0]);
        } catch (Throwable $e) {
            return ['domain' => null, 'topic' => null, 'severity' => 'low', 'rationale' => '分類失敗：'.$e->getMessage()];
        }

        $domain = $out['domain'] ?? null;
        $topic = $out['topic'] ?? null;

        // 防護：模型可能幻想出不存在的領域/主題 → 驗證後才採用
        $pack = is_string($domain) ? $this->registry->get($domain) : null;
        if ($pack === null) {
            return ['domain' => null, 'topic' => null, 'severity' => 'low', 'rationale' => (string) ($out['rationale'] ?? '無相符領域')];
        }
        if (! is_string($topic) || ! in_array($topic, $pack->eventTopics(), true)) {
            // 領域對但主題不合法 → 取該領域第一個主題
            $topic = $pack->eventTopics()[0] ?? null;
        }

        $severity = in_array($out['severity'] ?? null, ['low', 'medium', 'high', 'critical'], true)
            ? $out['severity'] : 'medium';

        return [
            'domain' => $pack->domain,
            'topic' => $topic,
            'severity' => $severity,
            'rationale' => (string) ($out['rationale'] ?? ''),
        ];
    }
}
