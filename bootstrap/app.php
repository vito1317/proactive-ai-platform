<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 站台位於 nginx + Cloudflare 之後，信任轉發標頭以正確識別 https / client IP
        $middleware->trustProxies(at: '*');

        // L1 webhook 由外部系統推送，無 session/CSRF token
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'api/voice/*',
            'api/vision*',   // 視覺端點（session 或共用密鑰驗證）
            'api/chat/*',    // 手機訊息對話端點（session 或共用密鑰驗證）
            'api/gateway/*', // 語音橋接（voice_server）以共用密鑰驗證，非 session
            'api/hitl/*',    // HITL 核准（手機通知按鈕，device token 驗證）
            'api/commute/*', // 通勤遲到提醒按鈕（device token 驗證）
            'api/automation/*', // 自動化流程按鈕 / 解鎖觸發
            'api/automations*', // 自動化列表 JSON（device token 驗證）
            'api/agent/*',   // 取消操作等 agent 控制
            'api/event/*',   // 行程出發提醒按鈕
            'api/sensor/*',  // 傳感器哨兵回報＋我沒事/需要幫忙按鈕（device token 驗證）
            'api/mail/*',    // 收件匣助理按鈕（device token 驗證）
            'api/notify-triage', // 通知分流（手機轉送，device token 驗證）
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('webhooks/*')
                || $request->expectsJson(),
        );
    })->create();
