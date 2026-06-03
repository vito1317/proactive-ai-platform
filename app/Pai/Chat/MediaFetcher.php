<?php

namespace App\Pai\Chat;

use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 從 Telegram / LINE 下載使用者傳來的圖片，轉成 data URI（base64），
 * 供多模態 LLM（vision）作為訊息內容。
 */
class MediaFetcher
{
    public function __construct(private readonly Settings $settings) {}

    /** Telegram：file_id → data URI。 */
    public function telegram(string $fileId): ?string
    {
        $token = $this->settings->get('notify.telegram.token');
        if (! $token) {
            return null;
        }
        try {
            $path = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $fileId])
                ->json('result.file_path');
            if (! $path) {
                return null;
            }
            $resp = Http::timeout(30)->get("https://api.telegram.org/file/bot{$token}/{$path}");
            if ($resp->failed()) {
                return null;
            }

            return $this->dataUri($resp->body(), $this->mimeFromPath($path));
        } catch (Throwable) {
            return null;
        }
    }

    /** LINE：message id → data URI（從 api-data 取內容）。 */
    public function line(string $messageId): ?string
    {
        $token = $this->settings->get('notify.line.token');
        if (! $token) {
            return null;
        }
        try {
            $resp = Http::timeout(30)->withToken($token)
                ->get("https://api-data.line.me/v2/bot/message/{$messageId}/content");
            if ($resp->failed()) {
                return null;
            }

            return $this->dataUri($resp->body(), $resp->header('Content-Type') ?: 'image/jpeg');
        } catch (Throwable) {
            return null;
        }
    }

    private function dataUri(string $bytes, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
