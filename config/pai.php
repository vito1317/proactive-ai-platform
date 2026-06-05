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
            'webhook_secret' => env('PAI_TELEGRAM_WEBHOOK_SECRET'), // setWebhook 自動產生
            'channels' => [],                                // 已知頻道（webhook/getUpdates 自動登錄）
        ],
        'line' => [
            'token' => env('PAI_LINE_TOKEN'),                // LINE Messaging API channel access token
            'secret' => env('PAI_LINE_SECRET'),              // LINE Channel secret（雙向 webhook 驗證）
            'to' => env('PAI_LINE_TO'),                      // 推播目標 userId/groupId
            'channels' => [],                                // 已知頻道（webhook 自動登錄）
        ],
    ],

    // 技能：高風險自我修改技能是否免對話確認直接執行（預設否 → 需對話回「確認」）
    'skills' => [
        'allow_system_writes' => env('PAI_SKILLS_ALLOW_WRITES', false),
    ],

    // 語音轉文字（STT）：本機 MiniCPM-o 語音服務的 transcribe 端點
    'voice' => [
        'stt_url' => env('PAI_STT_URL', 'http://127.0.0.1:8891/voice/transcribe'),

        // 全雙工語音（瀏覽器 ↔ voice_server :8891 Socket.IO，即時對話 + 操控系統）
        'fullduplex_enabled' => env('PAI_VOICE_FD_ENABLED', true),
        // 瀏覽器要連的 Socket.IO 來源（經 nginx/WAF 反代到 :8891）；空字串=同源
        'fullduplex_url' => env('PAI_VOICE_FD_URL', ''),
        // Socket.IO path（nginx 反代路徑）
        'fullduplex_path' => env('PAI_VOICE_FD_PATH', '/voice-rt/socket.io'),
        // voice_server 在每輪把逐字稿回送本平台 agentic 引擎時用的共用密鑰
        'agent_secret' => env('VOICE_AGENT_SECRET', 'pai-voice-2f9c7a1e'),
        // 預設在哪個節點操作（開/關程式等）：'local'=主節點本機，或某個已註冊的 MCP gateway 名稱（如 'mac'）
        'default_gateway' => env('PAI_VOICE_DEFAULT_GATEWAY', 'local'),
        // 背景任務完成後把結果念回 /voice 的端點（voice_server 的 /voice/push）
        'push_url' => env('PAI_VOICE_PUSH_URL', 'http://127.0.0.1:8891/voice/push'),
        // 語音助理人格（system prompt）—— 不要自稱 PAI（唸起來像「屁」）
        'system_prompt' => env('PAI_VOICE_PROMPT', '你是一個語音助理，名字叫「智慧助理」，全程用台灣正體（繁體）中文、口語、簡短。如果被問『你是誰、你叫什麼、誰開發的、什麼模型』，一律只回答：「我是智慧助理，這個平台的語音助理。」簡單的打招呼、閒聊可以直接回。但只要使用者要你『排行程、規劃、比價、找住宿/機票、上網查資料、查系統狀態、開關程式、播放音樂、執行任何操作』，你【只能回一句】「好，我來幫你處理」，然後就停住——絕對不要自己列出內容、行程、清單或答案，那些一律由平台實際去做。'),
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
