<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Settings\Settings;
use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Http;
use Throwable;

/** 用 FAL 跑生成模型（FLUX 生圖、影片等）。model=FAL 模型路徑(預設 fal-ai/flux/schnell)；prompt=描述。需 fal.api_key。 */
class FalGenerateSkill implements Skill
{
    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return 'fal-generate';
    }

    public function description(): string
    {
        return '用 FAL 生成圖片/影片等。prompt=描述；model=FAL 模型路徑(可選，預設 fal-ai/flux/schnell)。回傳產物網址。';
    }

    public function parameters(): array
    {
        return [
            'prompt' => '要生成的內容描述',
            'model' => 'FAL 模型路徑（可選，如 fal-ai/flux/schnell、fal-ai/ltx-video）',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $prompt = trim((string) ($args['prompt'] ?? ''));
        if ($prompt === '') {
            return '請描述要生成什麼。';
        }
        $key = (string) $this->settings->get('fal.api_key', '', \App\Pai\Agent\Tenant::id());
        if ($key === '') {
            return '尚未設定 FAL API Key（後台「設定 → 供應商」）。';
        }
        $model = trim((string) ($args['model'] ?? '')) ?: 'fal-ai/flux/schnell';
        try {
            // FAL 同步端點：直接回結果（簡單模型適用）
            $resp = Http::timeout(180)->withHeaders(['Authorization' => 'Key '.$key])
                ->post('https://fal.run/'.$model, ['prompt' => $prompt]);
            if (! $resp->successful()) {
                return 'FAL 生成失敗（HTTP '.$resp->status().'）：'.mb_substr($resp->body(), 0, 200);
            }
            $json = $resp->json();
            // 常見回傳：images[0].url 或 video.url
            $url = $json['images'][0]['url'] ?? $json['image']['url'] ?? $json['video']['url'] ?? '';
            if ($url === '' && isset($json['url'])) {
                $url = $json['url'];
            }

            return $url !== '' ? "🎨 已生成：{$url}" : '已生成，但回應沒有可用網址：'.mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300);
        } catch (Throwable $e) {
            return 'FAL 生成失敗：'.$e->getMessage();
        }
    }
}
