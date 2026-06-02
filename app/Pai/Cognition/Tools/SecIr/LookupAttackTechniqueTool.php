<?php

namespace App\Pai\Cognition\Tools\SecIr;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tool;
use App\Pai\Cognition\ToolResult;

/**
 * 查詢 MITRE ATT&CK 技術（sec-ir 的知識層 / L2）。
 * 內建關鍵字 → 技術對照，協助分流時建立攻擊鏈關聯。
 */
final class LookupAttackTechniqueTool implements Tool
{
    /** 關鍵字（中英） => [technique_id, name]。 */
    private const KB = [
        'brute' => ['T1110', 'Brute Force'],
        '暴力' => ['T1110', 'Brute Force'],
        'ransomware' => ['T1486', 'Data Encrypted for Impact'],
        '勒索' => ['T1486', 'Data Encrypted for Impact'],
        'phish' => ['T1566', 'Phishing'],
        '釣魚' => ['T1566', 'Phishing'],
        'lateral' => ['T1021', 'Remote Services (Lateral Movement)'],
        '橫向' => ['T1021', 'Remote Services (Lateral Movement)'],
        'exfil' => ['T1041', 'Exfiltration Over C2 Channel'],
        '外洩' => ['T1041', 'Exfiltration Over C2 Channel'],
        'privilege' => ['T1068', 'Exploitation for Privilege Escalation'],
        '提權' => ['T1068', 'Exploitation for Privilege Escalation'],
        'credential' => ['T1003', 'OS Credential Dumping'],
        '憑證' => ['T1003', 'OS Credential Dumping'],
        'persistence' => ['T1547', 'Boot or Logon Autostart Execution'],
        'command and control' => ['T1071', 'Application Layer Protocol (C2)'],
        'malware' => ['T1059', 'Command and Scripting Interpreter'],
        'anomaly' => ['T1078', 'Valid Accounts'],
        'cloudtrail' => ['T1078', 'Valid Accounts'],
    ];

    public function name(): string
    {
        return 'lookup_attack_technique';
    }

    public function description(): string
    {
        return '查詢 MITRE ATT&CK 技術對照。action_input: {"keyword":"brute-force"} 或省略以本次事件內容比對。';
    }

    public function run(array $input, AgentContext $ctx): ToolResult
    {
        $haystack = strtolower(trim((string) ($input['keyword'] ?? '')));
        if ($haystack === '') {
            $haystack = strtolower($ctx->event->topic.' '.($ctx->event->intent ?? '').' '.json_encode($ctx->event->payload, JSON_UNESCAPED_UNICODE));
        }

        $hits = [];
        foreach (self::KB as $kw => [$id, $name]) {
            if (str_contains($haystack, $kw)) {
                $hits[$id] = "{$id} {$name}";
            }
        }

        if ($hits === []) {
            return ToolResult::ok('無對應的 ATT&CK 技術（建議人工研判）。');
        }

        return ToolResult::ok("對應 ATT&CK 技術：\n".implode("\n", $hits));
    }
}
