<?php

namespace App\Pai\Chat;

use App\Pai\Settings\Settings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 從 Telegram / LINE 下載使用者傳來的圖片，轉成 data URI（base64），
 * 供多模態 LLM（vision）作為訊息內容。
 */
class MediaFetcher
{
    public function __construct(private readonly Settings $settings) {}

    /** Telegram：file_id → 圖片 data URI（vision 用）。 */
    public function telegram(string $fileId): ?string
    {
        [$bytes, $path] = $this->telegramBytes($fileId);

        return $bytes === null ? null : $this->dataUri($bytes, $this->mimeFromPath((string) $path));
    }

    /** Telegram：file_id → 原始音檔 base64（STT 用）。 */
    public function telegramAudio(string $fileId): ?string
    {
        [$bytes] = $this->telegramBytes($fileId);

        return $bytes === null ? null : base64_encode($bytes);
    }

    /** LINE：message id → 圖片 data URI（vision 用）。 */
    public function line(string $messageId): ?string
    {
        $resp = $this->lineContent($messageId);

        return $resp === null ? null : $this->dataUri($resp->body(), $resp->header('Content-Type') ?: 'image/jpeg');
    }

    /** LINE：message id → 原始音檔 base64（STT 用）。 */
    public function lineAudio(string $messageId): ?string
    {
        $resp = $this->lineContent($messageId);

        return $resp === null ? null : base64_encode($resp->body());
    }

    /** @return array{0: ?string, 1: ?string} [原始 bytes, file_path] */
    private function telegramBytes(string $fileId): array
    {
        $token = $this->settings->get('notify.telegram.token');
        if (! $token) {
            return [null, null];
        }
        try {
            $path = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $fileId])
                ->json('result.file_path');
            if (! $path) {
                return [null, null];
            }
            $resp = Http::timeout(60)->get("https://api.telegram.org/file/bot{$token}/{$path}");

            return $resp->failed() ? [null, null] : [$resp->body(), $path];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private function lineContent(string $messageId): ?Response
    {
        $token = $this->settings->get('notify.line.token');
        if (! $token) {
            return null;
        }
        try {
            $resp = Http::timeout(60)->withToken($token)
                ->get("https://api-data.line.me/v2/bot/message/{$messageId}/content");

            return $resp->failed() ? null : $resp;
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
