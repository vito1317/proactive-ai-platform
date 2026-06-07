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
            'api/gateway/*', // 語音橋接（voice_server）以共用密鑰驗證，非 session
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
