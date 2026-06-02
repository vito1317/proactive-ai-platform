<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 領域包 (Domain Pack) 載入
    |--------------------------------------------------------------------------
    | 平台啟動時 DomainPackLoader 會掃描此目錄下的 *.yaml，依
    | docs/SPEC.md 的契約驗證後註冊到 DomainRegistry。
    */
    'packs_path' => env('PAI_PACKS_PATH', base_path('packs')),

    // 合法的自治階段（對應 docs/SPEC.md §2.6 漸進式授權）
    'autonomy_levels' => ['copilot', 'supervisor', 'autopilot'],

    // 合法的多智能體拓樸（對應 docs/ARCHITECTURE.md §3）
    'topologies' => ['router', 'sequential', 'parallel', 'competitive'],

    // 工具權限與風險等級
    'tool_perms' => ['read', 'write', 'exec'],
    'risk_levels' => ['low', 'medium', 'high'],

    // 知識來源型別（L2）
    'knowledge_types' => ['vector', 'graph', 'doc'],

    /*
    |--------------------------------------------------------------------------
    | L3 認知大腦 (Cognitive Engine)
    |--------------------------------------------------------------------------
    | 預設指向本機 llama-server（OpenAI 相容 /v1）。production 可改指雲端模型。
    */
    'llm' => [
        'base_url' => env('PAI_LLM_BASE_URL', 'http://127.0.0.1:10003/v1'),
        'model' => env('PAI_LLM_MODEL', 'local'),       // llama-server 用已載入模型，名稱僅作記錄
        'api_key' => env('PAI_LLM_API_KEY', 'sk-local'),
        'temperature' => (float) env('PAI_LLM_TEMPERATURE', 0.2),
        // 思考型模型（gemma 帶 reasoning_content）需足夠額度：先推理再產出答案
        'max_tokens' => (int) env('PAI_LLM_MAX_TOKENS', 3072),
        'timeout' => (int) env('PAI_LLM_TIMEOUT', 180),
    ],

    // ReAct 迴圈上限（含反思）
    'react' => [
        'max_steps' => (int) env('PAI_REACT_MAX_STEPS', 6),
        'reflect' => (bool) env('PAI_REACT_REFLECT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | L2 記憶（向量 RAG）
    |--------------------------------------------------------------------------
    | store 驅動：database（SQLite/MySQL，PHP 餘弦）或 pgvector（production）。
    | embeddings：local（離線 feature-hashing）或 openai（相容 /v1/embeddings）。
    */
    'memory' => [
        'store' => env('PAI_MEMORY_STORE', 'database'),  // database | pgvector
        'top_k' => (int) env('PAI_MEMORY_TOPK', 3),
        'embeddings' => [
            'driver' => env('PAI_EMBED_DRIVER', 'local'),  // local | openai
            'dim' => (int) env('PAI_EMBED_DIM', 256),
            'base_url' => env('PAI_EMBED_URL', 'http://127.0.0.1:10003/v1'),
            'model' => env('PAI_EMBED_MODEL', 'text-embedding-3-small'),
            'api_key' => env('PAI_EMBED_KEY', 'sk-local'),
        ],
    ],

    // 推播通知：待核准時推到中控台鈴鐺 + 外部平台（皆可在後台設定）
    'notify' => [
        'webhook_url' => env('PAI_NOTIFY_WEBHOOK'),          // Slack/Discord 相容 {text}
        'telegram' => [
            'token' => env('PAI_TELEGRAM_TOKEN'),            // BotFather 取得
            'chat_id' => env('PAI_TELEGRAM_CHAT_ID'),
        ],
        'line' => [
            'token' => env('PAI_LINE_TOKEN'),                // LINE Messaging API channel access token
            'to' => env('PAI_LINE_TO'),                      // 推播目標 userId/groupId
        ],
    ],

    // 一鍵安裝來源（中控台顯示安裝指令用）
    'install' => [
        'repo_url' => env('PAI_REPO_URL', 'https://github.com/vito1317/proactive-ai-platform.git'),
    ],

    // log-ops 領域：要監控的日誌檔（逗號分隔）。LogScanner 只處理上次掃描後的新行。
    'logops' => [
        'sources' => array_values(array_filter(array_map('trim', explode(
            ',', (string) env('PAI_LOGOPS_SOURCES', storage_path('app/demo-app.log')),
        )))),
        // 觸發偵測的關鍵字（不分大小寫）
        'patterns' => ['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT', 'Fatal', 'Exception', 'Stack trace'],
        'excerpt_lines' => 6,  // 命中行後附帶幾行（捕捉堆疊）
    ],

    // DevAuto 領域：操作的目標 repo（唯讀檢視 / 沙盒內跑測試）
    'devauto' => [
        'repo_path' => env('PAI_DEVAUTO_REPO', storage_path('app/devauto-repo')),
        'test_entry' => env('PAI_DEVAUTO_TEST', 'run_tests.py'),
    ],

    // SecIR 領域：外部 EDR 端點（設定後 query_endpoint 會經 EgressGateway 注入憑證實打；
    // 未設定則回傳模擬遙測，方便本機示範）
    'secir' => [
        'edr_url' => env('PAI_SECIR_EDR_URL'),
        // 遏制動作的執行端點（設定後核准時經 EgressGateway 注入憑證實打；未設定則模擬）
        'containment_url' => env('PAI_SECIR_CONTAINMENT_URL'),
    ],

];
