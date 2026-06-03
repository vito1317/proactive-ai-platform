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
    Route::post('/stream/chat', [ChatStreamController::class, 'stream'])->name('chat.stream'); // SSE 串流

    Route::post('/console/ask', [ConsoleController::class, 'ask'])->name('console.ask');
    Route::post('/console/commands', [ConsoleController::class, 'dispatchEvent'])->name('console.commands');
    Route::post('/console/runs/{run}/decision', [ConsoleController::class, 'decide'])->name('console.decide');
    Route::post('/notifications/read', [ConsoleController::class, 'markNotificationsRead'])->name('notifications.read');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');

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

// L1 感知層事件入口（外部系統推送，公開、CSRF 豁免，見 bootstrap/app.php）
Route::post('/webhooks/{source}', [WebhookController::class, 'store']);
