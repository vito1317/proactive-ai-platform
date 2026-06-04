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

    /**
     * 強制技能訊號：訊息含這些「明確要實際執行/查系統」的記號時，
     * 直接判為 skill（要真的動手做），不交給慢又不穩的 LLM 分類。
     * 這解決「使用者明明叫我安裝/跑指令，卻被當閒聊只回『我來執行』」的問題。
     */
    private const SKILL_FORCE = [
        // shell / 安裝 / 套件
        'curl ', 'wget ', '| bash', '|bash', 'bash <', 'sh -c', '.sh', 'install.sh', 'sudo ',
        'apt ', 'apt-get', 'yum ', 'dnf ', 'brew ', 'npm ', 'pnpm ', 'yarn ', 'pip ', 'composer ',
        'git clone', 'chmod', 'chown', './', 'systemctl', 'service ', 'journalctl',
        // docker / nginx / 系統檢查
        'docker ', 'docker exec', 'nginx', 'php-fpm', 'df -h', 'df ', 'free -', 'ps aux', 'ps -ef',
        'top ', 'netstat', 'ss -', 'lsof', 'uptime', 'uname',
        // 路徑（要讀/查實際檔案）
        '/etc/', '/var/', '/opt/', '/usr/', '/home/', '/tmp/', '.conf', '.log', '.yaml', '.yml',
        // 明確執行 / 讀取 / 狀態確認 動詞片語
        '安裝', '跑指令', '跑一下', '跑個', '執行指令', '執行這', '執行終端', '執行 docker', '執行一下',
        '讀取檔', '讀檔', '讀取 nginx', '讀取設定', '看 log', '看日誌', '查看 log', '查看日誌',
        '查看設定', '查設定', '看設定檔', '查看 nginx', '查 nginx', 'tail ',
        '好了嗎', '成功了嗎', '裝好了嗎', '完成了嗎', '跑完了嗎', '裝好沒', '好了沒',
    ];

    /** @return array{category: string, reason: string} */
    public function classify(string $message): array
    {
        $lower = mb_strtolower($message);

        // 最高優先：明確要實際執行/查系統 → 直接 skill（不靠 LLM，快又穩）
        foreach (self::SKILL_FORCE as $kw) {
            if (str_contains($lower, $kw)) {
                return ['category' => 'skill', 'reason' => "明確執行/查系統訊號「{$kw}」→ 直接動手"];
            }
        }

        // 快速路徑：無任何動作訊號 → 直接當閒聊，不呼叫 LLM（分類呼叫在慢速思考模型上要 1～2 分鐘）
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
        - chat：一般對話、閒聊、問平台能做什麼、釐清需求。
        - skill：**查詢或操作平台/系統本身**——這是「立即可回答/執行」的，優先於 task。包含：
          ‧查詢類（請告訴我/列出/有哪些/查看/看一下）：某領域監控什麼、領域清單與細節、目前設定、日誌內容、MCP 工具、檔案內容、系統狀態、上網查資料。
          ‧操作類：改設定/切模型、停用啟用/整合領域包、重啟 worker、中止任務、執行終端機指令、讀寫/編輯檔案、開啟程式、接入管理 MCP、設定「一律允許」。
          ‧**只要是要「實際讀取檔案 / 查看或分析 log / 跑指令 / docker exec / 看某設定檔（如 nginx.conf）/ 查系統狀態」就一定是 skill（要真的去執行，不是聊天）**——例如「讀取 nginx error log」「查看 /etc/nginx 設定」「跑 df -h」「docker exec 看容器日誌」。
        - task：要 AI 「去處理一件需要多步推理的事」並交給某個領域協調者背景執行（資安事件響應、調查入侵、修 bug、隔離主機、處理日誌錯誤並自動修復…）。**只有「請 AI 動手處理某事件/案件」才算 task；單純「告訴我/查一下」是 skill。**
        - new_domain：想「新增一個持續性的監控/自動化領域」（描述長期職責，例如「監控 X 並自動 Y」）。
        - configure_notify：要設定通知平台，訊息含 Telegram/LINE/Slack/webhook 的 token、chat id 或推播對象。

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
