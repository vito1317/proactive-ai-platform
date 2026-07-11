<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\LineWebhookController;
use App\Http\Controllers\NotifyController;
use App\Http\Controllers\PacksController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\VoiceAgentController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// 登入
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// 中控台（需登入）
Route::middleware('auth')->group(function () {
    Route::get('/', [ConsoleController::class, 'index'])->name('console');

    // 對話式指揮 AI
    Route::get('/chat', [ChatController::class, 'index'])->name('chat');
    Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send'); // 非串流後備
    Route::post('/chat/new', [ChatController::class, 'new'])->name('chat.new');
    Route::post('/chat/clear', [ChatController::class, 'clear'])->name('chat.clear');
    Route::post('/chat/stop', [ChatController::class, 'stop'])->name('chat.stop'); // 終止回覆 / 插話
    Route::post('/chat/queue', [ChatController::class, 'queue'])->name('chat.queue'); // SSE 失敗時的非串流後備
    Route::post('/stream/chat', [ChatStreamController::class, 'stream'])->name('chat.stream'); // SSE 串流
    Route::get('/chat/events/{event}', [ChatController::class, 'eventStatus'])->name('chat.event_status');

    Route::get('/voice', fn () => \Inertia\Inertia::render('Voice'))->name('voice');
    Route::get('/vision', fn () => \Inertia\Inertia::render('Vision'))->name('vision.page');
    Route::get('/mcp/health', [ConsoleController::class, 'mcpHealth'])->name('mcp.health'); // 節點連線狀態
    Route::get('/console/agent-ops', [ConsoleController::class, 'agentOps'])->name('console.agent_ops'); // 即時作業流（AgentOpsFlow 輪詢）
    Route::get('/api/gateway/pair-code', [\App\Http\Controllers\GatewayController::class, 'pairCode'])->name('gateway.paircode'); // Android 一鍵配對碼（/gateway/ 被反代，改用 /api/）
    Route::post('/api/gateway/pair-create', [\App\Http\Controllers\GatewayController::class, 'pairCreate'])->name('gateway.paircreate'); // 一次性配對碼(綁目前帳號)

    Route::post('/console/ask', [ConsoleController::class, 'ask'])->name('console.ask');
    Route::post('/console/commands', [ConsoleController::class, 'dispatchEvent'])->name('console.commands');
    Route::post('/console/runs/{run}/decision', [ConsoleController::class, 'decide'])->name('console.decide');
    Route::post('/notifications/read', [ConsoleController::class, 'markNotificationsRead'])->name('notifications.read');
    Route::post('/console/scheduled/{id}/cancel', [ConsoleController::class, 'cancelScheduled'])->name('console.scheduled.cancel');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

    // Agent Profiles（人格/模式）—— 每帳號自己的
    Route::get('/agent/profiles', [\App\Http\Controllers\AgentProfilesController::class, 'index'])->name('agent.profiles');
    Route::post('/agent/profiles', [\App\Http\Controllers\AgentProfilesController::class, 'save'])->name('agent.profiles.save');
    Route::post('/agent/profiles/activate', [\App\Http\Controllers\AgentProfilesController::class, 'activate'])->name('agent.profiles.activate');

    // 自動化流程 + AI 主動思考記錄（web 頁）
    Route::get('/automations', [\App\Http\Controllers\AutomationsController::class, 'page'])->name('automations');

    // MCP 伺服器管理（per-account）
    Route::get('/agent/mcp', [\App\Http\Controllers\McpController::class, 'index'])->name('agent.mcp');
    Route::post('/agent/mcp', [\App\Http\Controllers\McpController::class, 'store'])->name('agent.mcp.store');
    Route::get('/agent/mcp/{server}/test', [\App\Http\Controllers\McpController::class, 'test'])->name('agent.mcp.test');
    Route::delete('/agent/mcp/{server}', [\App\Http\Controllers\McpController::class, 'destroy'])->name('agent.mcp.destroy');

    // 管理員：帳號管理 + 逐資源授權
    Route::get('/admin/accounts', [\App\Http\Controllers\AdminController::class, 'index'])->name('admin.accounts');
    Route::post('/admin/accounts', [\App\Http\Controllers\AdminController::class, 'store'])->name('admin.accounts.store');
    Route::post('/admin/accounts/{user}', [\App\Http\Controllers\AdminController::class, 'update'])->name('admin.accounts.update');
    Route::post('/admin/accounts/{user}/devices', [\App\Http\Controllers\AdminController::class, 'setDevices'])->name('admin.accounts.devices');
    Route::post('/admin/accounts/{user}/skills', [\App\Http\Controllers\AdminController::class, 'setSkills'])->name('admin.accounts.skills');
    Route::post('/admin/accounts/{user}/password', [\App\Http\Controllers\AdminController::class, 'resetPassword'])->name('admin.accounts.password');
    Route::delete('/admin/accounts/{user}', [\App\Http\Controllers\AdminController::class, 'destroy'])->name('admin.accounts.destroy');

    // 用自然語言新增領域包
    Route::get('/packs', [PacksController::class, 'index'])->name('packs');
    Route::post('/packs/generate', [PacksController::class, 'generate'])->name('packs.generate');
    Route::post('/packs/save', [PacksController::class, 'save'])->name('packs.save');

    // 通知平台（AI 引導設定 + 測試）
    Route::post('/notify/assist', [NotifyController::class, 'assist'])->name('notify.assist');
    Route::post('/notify/test', [NotifyController::class, 'test'])->name('notify.test');
    // 通知頻道（TG/LINE）：列出 / 刷新 / 選取
    Route::get('/notify/channels', [NotifyController::class, 'channels'])->name('notify.channels');
    Route::post('/notify/channels/refresh', [NotifyController::class, 'refreshChannels'])->name('notify.channels.refresh');
    Route::post('/notify/channels/select', [NotifyController::class, 'selectChannel'])->name('notify.channels.select');
});

// 一鍵安裝腳本（公開）：curl -fsSL https://<域名>/install.sh | bash -s -- ...
Route::get('/install.sh', function () {
    $path = base_path('install.sh');

    abort_unless(is_file($path), 404);

    return response(file_get_contents($path), 200, ['Content-Type' => 'text/x-shellscript; charset=utf-8']);
})->name('install.sh');

// Telegram 接收（雙向）— 須在 {source} 之前；公開、CSRF 豁免
Route::post('/webhooks/telegram', [TelegramWebhookController::class, 'handle'])->name('webhooks.telegram');

// LINE 接收（雙向）— 須在 {source} 之前；公開、CSRF 豁免
Route::post('/webhooks/line', [LineWebhookController::class, 'handle'])->name('webhooks.line');
Route::post('/webhooks/discord', [\App\Http\Controllers\DiscordWebhookController::class, 'handle'])->name('webhooks.discord'); // Discord Interactions /ask
Route::post('/webhooks/slack', [\App\Http\Controllers\SlackWebhookController::class, 'handle'])->name('webhooks.slack'); // Slack Events API
Route::post('/webhooks/feishu', [\App\Http\Controllers\FeishuWebhookController::class, 'handle'])->name('webhooks.feishu'); // Feishu/Lark Events
Route::post('/webhooks/dingtalk', [\App\Http\Controllers\DingTalkWebhookController::class, 'handle'])->name('webhooks.dingtalk'); // DingTalk bot
Route::post('/webhooks/mattermost', [\App\Http\Controllers\MattermostWebhookController::class, 'handle'])->name('webhooks.mattermost'); // Mattermost outgoing
Route::post('/webhooks/twilio', [\App\Http\Controllers\TwilioWebhookController::class, 'handle'])->name('webhooks.twilio'); // SMS (Twilio)
Route::post('/webhooks/call/turn/{token}', [\App\Http\Controllers\OutboundCallController::class, 'turn'])->name('webhooks.call.turn'); // AI 外撥：通話回合 TwiML
Route::post('/webhooks/call/status/{token}', [\App\Http\Controllers\OutboundCallController::class, 'status'])->name('webhooks.call.status'); // AI 外撥：通話結束回呼
Route::post('/webhooks/onebot', [\App\Http\Controllers\OneBotWebhookController::class, 'handle'])->name('webhooks.onebot'); // QQ (OneBot)
Route::post('/webhooks/bluebubbles', [\App\Http\Controllers\BlueBubblesWebhookController::class, 'handle'])->name('webhooks.bluebubbles'); // iMessage (BlueBubbles)

// 全雙工語音橋接：voice_server 把每輪逐字稿回送 agentic 引擎（共用密鑰驗證，CSRF 豁免）
Route::post('/api/voice/agent', [VoiceAgentController::class, 'handle'])->name('voice.agent');
Route::post('/api/voice/agent-stream', [VoiceAgentController::class, 'stream'])->name('voice.agent.stream');
Route::post('/api/voice/announce', [VoiceAgentController::class, 'announce'])->name('voice.announce'); // 主動念一句（開車模式念通知）
Route::post('/api/vision', [\App\Http\Controllers\VisionController::class, 'analyze'])->name('vision');
Route::post('/api/vision/attach', [\App\Http\Controllers\VisionController::class, 'attach'])->name('vision.attach');

// 手機/原生端訊息對話（非串流，secret 認證；與 web 共用同一批對話）
Route::post('/api/chat/list', [\App\Http\Controllers\MobileChatController::class, 'list'])->name('mchat.list');
Route::post('/api/chat/history', [\App\Http\Controllers\MobileChatController::class, 'history'])->name('mchat.history');
Route::post('/api/chat/new', [\App\Http\Controllers\MobileChatController::class, 'new'])->name('mchat.new');
Route::post('/api/chat/send', [\App\Http\Controllers\MobileChatController::class, 'send'])->name('mchat.send');
Route::post('/api/chat/stream', [\App\Http\Controllers\MobileChatController::class, 'stream'])->name('mchat.stream'); // SSE 即時串流
Route::post('/api/chat/stop', [\App\Http\Controllers\MobileChatController::class, 'stop'])->name('mchat.stop');

// 裝置端 HITL 核准：手機通知「接受/拒絕」按鈕直接打這裡（X-Register-Secret 認證）
Route::post('/api/hitl/decide', [\App\Http\Controllers\HitlController::class, 'decide'])->name('hitl.decide');
// 通勤遲到提醒：手機通知「傳給主管/不用」按鈕
Route::post('/api/commute/decide', [\App\Http\Controllers\CommuteController::class, 'decide'])->name('commute.decide');
// 手機解鎖時觸發早晨通勤檢查（醒來即提醒）
Route::post('/api/commute/wake', [\App\Http\Controllers\CommuteController::class, 'wake'])->name('commute.wake');
// 通用自動化流程：ask 動作的按鈕 + 解鎖觸發
Route::post('/api/sensor/event', [\App\Http\Controllers\SensorEventController::class, 'event'])->name('sensor.event'); // 手機傳感器哨兵（撞擊/跌倒/碰撞警示）
Route::post('/api/sensor/decide', [\App\Http\Controllers\SensorEventController::class, 'decide'])->name('sensor.decide'); // 「我沒事/需要幫忙」按鈕
Route::get('/api/sensor/config', [\App\Http\Controllers\SensorEventController::class, 'config'])->name('sensor.config'); // 哨兵設定同步（心率門檻等）
Route::post('/api/automation/decide', [\App\Http\Controllers\AutomationController::class, 'decide'])->name('automation.decide');
Route::post('/api/automation/wake', [\App\Http\Controllers\AutomationController::class, 'wake'])->name('automation.wake');
// 取消操作：中止進行中的 agent（浮框「取消操作」鈕）
Route::post('/api/agent/abort', [\App\Http\Controllers\AutomationController::class, 'abort'])->name('agent.abort');
// 行程出發提醒按鈕（開導航/通知對方/知道了）
Route::post('/api/event/decide', [\App\Http\Controllers\EventController::class, 'decide'])->name('event.decide');
// 自動化列表 + AI 思考記錄（手機/原生端 JSON；session 或 device token 認證）
Route::get('/api/agent-ops', [\App\Http\Controllers\ConsoleController::class, 'agentOpsApi'])->name('agent_ops.api'); // 手機端即時作業流
Route::get('/api/automations', [\App\Http\Controllers\AutomationsController::class, 'data'])->name('automations.data');
Route::post('/api/automations/{id}/toggle', [\App\Http\Controllers\AutomationsController::class, 'toggle'])->name('automations.toggle');
Route::post('/api/automations/builtin', [\App\Http\Controllers\AutomationsController::class, 'builtin'])->name('automations.builtin');

Route::post("/api/gateway/pair", [GatewayController::class, "pair"])->name("gateway.pair");          // 兌換配對碼→長期 per-device 憑證
Route::post("/api/gateway/register", [GatewayController::class, "register"])->name("gateway.register");
Route::get("/api/gateway/poll", [GatewayController::class, "poll"])->name("gateway.poll");       // 反向節點 long-poll 取指令
Route::post("/api/gateway/result", [GatewayController::class, "result"])->name("gateway.result"); // 反向節點回傳結果

// L1 感知層事件入口（外部系統推送，公開、CSRF 豁免，見 bootstrap/app.php）
Route::post('/webhooks/{source}', [WebhookController::class, 'store']);
