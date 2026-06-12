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

    /** 進 vision prompt 的圖片大小上限（base64 後會再膨脹 4/3，太大會吃爆 context）。 */
    private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

    /** 超限時縮圖的最長邊（px）。 */
    private const DOWNSCALE_MAX_EDGE = 1600;

    private function dataUri(string $bytes, string $mime): ?string
    {
        // 極小圖防護：1×1 之類的退化圖會打掛 llama.cpp 的視覺解碼（實測 crash）→ 直接略過
        $dim = @getimagesizefromstring($bytes);
        if (is_array($dim) && (($dim[0] ?? 0) < 8 || ($dim[1] ?? 0) < 8)) {
            \Illuminate\Support\Facades\Log::warning('圖片尺寸過小，略過 vision', ['w' => $dim[0] ?? 0, 'h' => $dim[1] ?? 0]);

            return null;
        }

        // 過大的圖先縮（vision 不需要原尺寸）；縮不了（非圖片/GD 不可用）就放棄，不要丟爆量 base64 給 LLM
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            $resized = $this->downscale($bytes);
            if ($resized === null) {
                \Illuminate\Support\Facades\Log::warning('圖片過大且無法縮圖，略過 vision', ['bytes' => strlen($bytes)]);

                return null;
            }
            [$bytes, $mime] = $resized;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /** @return ?array{0: string, 1: string} [jpeg bytes, mime]；失敗回 null */
    private function downscale(string $bytes): ?array
    {
        if (! extension_loaded('gd')) {
            return null;
        }
        try {
            $img = @imagecreatefromstring($bytes);
            if ($img === false) {
                return null;
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1.0, self::DOWNSCALE_MAX_EDGE / max(1, max($w, $h)));
            $out = $scale < 1.0
                ? imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)))
                : $img;
            ob_start();
            imagejpeg($out ?: $img, null, 82);
            $jpeg = (string) ob_get_clean();

            return $jpeg === '' ? null : [$jpeg, 'image/jpeg'];
        } catch (Throwable) {
            return null;
        }
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
