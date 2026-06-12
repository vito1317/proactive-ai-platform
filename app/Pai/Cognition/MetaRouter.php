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
        // 明確「讀取/查看領域包」→ 走技能實際讀取並逐步回報（非泛泛閒聊）
        '讀取領域包', '查看領域包', '看領域包', '看一下領域包', '領域包細節', '領域包內容',
        '領域包有哪些', '有哪些領域', '列出領域', '盤點領域', '領域包清單', '領域清單',
        // 節點 / gateway / MCP 連線狀態 → 走 list-mcp-servers 技能（即時 ping，不要憑空亂講）
        'gateway', '節點', '节点', 'mcp', '連線狀態', '连线状态', '連接狀態', '有哪些節點',
        '有幾個節點', '有幾個 gateway', '節點狀態', '哪些 gateway', '線上節點', '在線節點',
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

        $prompt = Prompts::render('meta-router', ['message' => $message]);

        try {
            $out = $this->llm->chatJson([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 512, 'tier' => 'small', 'temperature' => 0]);
        } catch (Throwable) {
            return ['category' => 'chat', 'reason' => '無法判斷，預設為對話']; // 安全預設：不誤觸動作
        }

        $cat = in_array($out['category'] ?? '', self::CATEGORIES, true) ? $out['category'] : 'chat';

        return ['category' => $cat, 'reason' => (string) ($out['reason'] ?? '')];
    }
}
