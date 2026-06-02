<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\PacksController;
use App\Http\Controllers\SettingsController;
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
});

// L1 感知層事件入口（外部系統推送，公開、CSRF 豁免，見 bootstrap/app.php）
Route::post('/webhooks/{source}', [WebhookController::class, 'store']);
