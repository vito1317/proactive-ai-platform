<?php

namespace App\Pai\Chat;

use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/** 語音轉文字（STT）：呼叫本機 MiniCPM-o 語音服務的 transcribe 端點。 */
class SpeechToText
{
    public function __construct(private readonly Settings $settings) {}

    /** @return ?string 逐字稿（失敗回 null） */
    public function transcribe(string $audioBase64, string $language = 'auto'): ?string
    {
        $url = $this->settings->get('voice.stt_url');
        if (! $url || $audioBase64 === '') {
            return null;
        }
        try {
            $resp = Http::timeout(150)->post($url, ['audio_base64' => $audioBase64, 'language' => $language]);
            if ($resp->successful()) {
                $text = trim((string) $resp->json('text'));

                return $text !== '' ? $text : null;
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }
}
