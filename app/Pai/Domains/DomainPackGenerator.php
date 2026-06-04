<?php

namespace App\Pai\Domains;

use App\Pai\Cognition\LlmClient;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * 用自然語言生成領域包 manifest：把使用者描述交給 LLM 產出符合 docs/SPEC.md
 * 契約的 manifest，經 {@see DomainPackValidator} 驗證（不過則回饋錯誤重試一次），
 * 再轉成 YAML。讓「加一個新領域」不必手寫 YAML。
 */
class DomainPackGenerator
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly DomainPackValidator $validator,
        private readonly DomainRegistry $registry,
    ) {}

    /**
     * @return array{manifest: ?array, yaml: string, valid: bool, errors: string[]}
     */
    public function generate(string $description): array
    {
        $messages = [['role' => 'user', 'content' => $this->prompt($description)]];

        $manifest = null;
        $errors = ['未能產生'];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $manifest = LlmClient::extractJson($this->llm->chat($messages));
            } catch (Throwable $e) {
                $errors = ['LLM 輸出解析失敗：'.$e->getMessage()];

                continue;
            }

            $errors = $this->validator->validate($manifest);
            if ($errors === []) {
                break;
            }

            // 把錯誤回饋給模型，要求修正後重出
            $messages[] = ['role' => 'assistant', 'content' => json_encode($manifest, JSON_UNESCAPED_UNICODE)];
            $messages[] = ['role' => 'user', 'content' => "上述 manifest 不合法，請修正這些問題後重新輸出完整 JSON：\n- ".implode("\n- ", $errors)];
        }

        $valid = $errors === [] && is_array($manifest);

        return [
            'manifest' => $manifest,
            'yaml' => $valid ? Yaml::dump($manifest, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) : '',
            'valid' => $valid,
            'errors' => $errors,
        ];
    }

    private function prompt(string $description): string
    {
        $existing = implode(', ', array_keys($this->registry->all())) ?: '（無）';

        return <<<PROMPT
        你是主動式 AI 平台的領域包產生器。依使用者描述，產生一份「領域包 manifest」JSON。
        只輸出一個 JSON 物件，不要任何其他文字。

        必填欄位與規則：
        - domain: kebab-case，唯一（不可與既有重複：{$existing}）
        - coordinator: kebab-case，建議 "<domain>-coordinator"
        - description: 一句話描述
        - triggers: { events: ["主題.子主題"...], cron: ["<cron運算式>: <說明>"...] }（events 與 cron 至少一個非空）
        - tools: [{ uri: "mcp://名稱", perms: ["read"|"write"|"exec"...], risk: "low"|"medium"|"high"(選填) }]（至少一項；破壞性工具標 risk:high）
        - agents: { topology: "router"|"sequential"|"parallel"|"competitive", roster: [{ name: kebab, role: "職責" }...] }
        - memory: { namespace: kebab(建議=domain), knowledge: [{ type: "vector"|"graph"|"doc", source: "來源" }...] }
        - risk_policy: { autonomy: "copilot"|"supervisor"|"autopilot", hitl_required: ["破壞性動作鍵"...], rate_limits: { "動作鍵": "次數/min|hour|day" }(選填) }
        - contracts: { output: "contracts/<名稱>.schema.json" }
        - slo: { ... }(選填)

        準則：破壞性/不可逆動作放進 hitl_required 並把對應工具標 risk:high；autonomy 預設 supervisor（保守領域用 copilot）。

        /no_think 直接輸出 JSON，不要思考、不要解釋、不要 markdown 圍欄。
        使用者描述：「{$description}」
        PROMPT;
    }
}
