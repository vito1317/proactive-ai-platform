<?php

namespace App\Pai\Cognition;

use Throwable;

/**
 * 後設路由：判斷使用者的自然語言屬於哪一類意圖，讓「指揮 AI」單一輸入框
 * 能自動分派——執行任務 / 新增領域 / 設定通知。
 */
class MetaRouter
{
    public const CATEGORIES = ['chat', 'task', 'new_domain', 'configure_notify', 'skill'];

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * 動作訊號：訊息含這些字才需要動用（很慢的）LLM 意圖分類；
     * 否則直接視為閒聊，省下一次完整 LLM 來回（大幅縮短「送出中」等待）。
     */
    private const ACTION_HINTS = [
        '幫我', '幫忙', '請你', '監控', '偵測', '設定', '設置', '通知', '推播', '搜尋', '查一下', '查詢', '上網',
        '執行', '跑一下', '跑個', '讀取', '讀檔', '寫入', '寫檔', '編輯', '修改', '刪除', '重啟', '啟動', '開啟',
        '停止', '中止', '取消', '終止', '新增', '建立', '整合', '合併', '領域', '安裝', '部署', '列出', '啟用', '停用',
        '切換', '調整', '重新整理', '一律允許', '記工時', 'token', 'http', 'mcp', 'line', 'telegram', 'slack',
        'webhook', '日誌', 'log', '重跑', '產生', '生成',
    ];

    /** @return array{category: string, reason: string} */
    public function classify(string $message): array
    {
        // 快速路徑：無任何動作訊號 → 直接當閒聊，不呼叫 LLM（分類呼叫在慢速思考模型上要 1～2 分鐘）
        $lower = mb_strtolower($message);
        $hasHint = false;
        foreach (self::ACTION_HINTS as $h) {
            if (str_contains($lower, $h)) {
                $hasHint = true;
                break;
            }
        }
        if (! $hasHint) {
            return ['category' => 'chat', 'reason' => '無動作訊號，直接對話'];
        }

        $prompt = <<<PROMPT
        判斷使用者訊息屬於下列哪一類，只輸出 JSON：{"category":"chat|task|new_domain|configure_notify|skill","reason":"一句話"}
        - chat：一般對話、提問、閒聊、問平台能做什麼、釐清需求（不需立即執行動作）
        - task：要 AI 立即執行/處理一件事（資安事件、修 bug、調查、處理日誌錯誤、開票…）
        - new_domain：想「新增一個持續性的監控/自動化領域」（描述一種長期職責，例如「監控 X 並自動 Y」）
        - configure_notify：要設定通知平台，訊息含 Telegram/LINE/Slack/webhook 的 token、chat id 或推播對象
        - skill：要「操作或修改平台/系統本身」——查看或修改平台設定、切換 LLM 模型或參數、停用/啟用領域包、重啟 worker、查看日誌、中止任務、執行終端機指令、讀寫檔案、上網搜尋或開網址、開啟程式、接入/管理 MCP 工具伺服器、設定「一律允許」

        （訊息可能附帶先前對話脈絡；以最後一則使用者意圖為準。）
        /no_think 直接輸出 JSON，不要思考、不要解釋。
        使用者訊息：「{$message}」
        PROMPT;

        try {
            $out = LlmClient::extractJson($this->llm->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 512]));
        } catch (Throwable) {
            return ['category' => 'chat', 'reason' => '無法判斷，預設為對話']; // 安全預設：不誤觸動作
        }

        $cat = in_array($out['category'] ?? '', self::CATEGORIES, true) ? $out['category'] : 'chat';

        return ['category' => $cat, 'reason' => (string) ($out['reason'] ?? '')];
    }
}
