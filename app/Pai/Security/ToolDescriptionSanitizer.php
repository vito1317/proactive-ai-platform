<?php

namespace App\Pai\Security;

/**
 * 清洗外部 / 社群 MCP 工具的描述文字（供應鏈防禦）。
 *
 * 對所有非自家來源「預設不信任」：移除零寬字元與控制碼、偵測並中和
 * 提示詞注入語句，回報可疑旗標供稽核。被標記的工具應被隔離或拒絕註冊。
 */
class ToolDescriptionSanitizer
{
    private const MAX_LEN = 2000;

    /** 類別 => 偵測樣式（不分大小寫）。 */
    private const PATTERNS = [
        'instruction_override' => [
            '/ignore\s+(all\s+)?(previous|prior|above)\s+instructions?/i',
            '/disregard\s+(the\s+)?(previous|prior|above|system)/i',
            '/你(現在)?是一個?/u',
            '/忽略(先前|之前|上述|所有)?\s*(的)?\s*(指令|指示|規則)/u',
            '/(假裝|假装|扮演)你是/u',
        ],
        'role_hijack' => [
            '/you\s+are\s+now\s+/i',
            '/act\s+as\s+(an?\s+)?/i',
            '/pretend\s+to\s+be/i',
            '/new\s+system\s+prompt/i',
        ],
        'secret_exfiltration' => [
            '/(reveal|print|output|send|dump|leak)\b.{0,40}\b(system\s+prompt|api[\s_-]?key|token|password|secret|credential)/i',
            '/(洩漏|外洩|印出|輸出|傳送).{0,20}(密鑰|金鑰|密碼|憑證|token)/u',
        ],
        'guardrail_bypass' => [
            '/(bypass|disable|override|turn\s+off)\b.{0,30}\b(guardrail|safety|policy|filter|restriction)/i',
            '/(繞過|關閉|停用).{0,20}(護欄|安全|限制|規則)/u',
        ],
    ];

    public function sanitize(string $description): SanitizationResult
    {
        // 1) 移除零寬字元與控制碼（保留換行/tab）
        $clean = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{2060}]/u', '', $description) ?? $description;
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean) ?? $clean;

        // 2) 偵測 + 中和注入語句
        $flags = [];
        foreach (self::PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $clean)) {
                    $flags[$category] = true;
                    $clean = preg_replace($pattern, '⟦已移除可疑內容⟧', $clean) ?? $clean;
                }
            }
        }

        // 3) 長度上限
        if (mb_strlen($clean) > self::MAX_LEN) {
            $clean = mb_substr($clean, 0, self::MAX_LEN).'…';
            $flags['oversized'] = true;
        }

        return new SanitizationResult(trim($clean), array_keys($flags));
    }
}
