<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Settings\Settings;
use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** 依文字生成圖片（呼叫 OpenAI 相容的 /images/generations 端點；端點/金鑰/模型在後台設定）。 */
class GenerateImageSkill implements Skill
{
    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return 'generate-image';
    }

    public function description(): string
    {
        return '依文字描述生成一張圖片。prompt=要畫什麼；size=尺寸(可選，如 1024x1024)。回傳圖片網址。';
    }

    public function parameters(): array
    {
        return [
            'prompt' => '要生成的圖片描述',
            'size' => '尺寸（可選，預設 1024x1024）',
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
            return '請描述要生成什麼圖片。';
        }
        $uid = \App\Pai\Agent\Tenant::id();   // 該帳號自己的金鑰（分權）
        $url = (string) $this->settings->get('image.api_url', config('pai.image.api_url'), $uid);
        $key = (string) $this->settings->get('image.api_key', config('pai.image.api_key'), $uid);
        $model = (string) $this->settings->get('image.model', config('pai.image.model'), $uid) ?: 'dall-e-3';
        if ($url === '') {
            return '尚未設定生圖端點，請到後台「設定 → 生圖」填入端點/金鑰/模型。';
        }
        $size = preg_match('/^\d{2,4}x\d{2,4}$/', (string) ($args['size'] ?? '')) ? $args['size'] : '1024x1024';

        try {
            $req = Http::timeout(120);
            if ($key !== '') {
                $req = $req->withToken($key);
            }
            $resp = $req->post(rtrim($url, '/'), [
                'model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => $size,
            ]);
            if (! $resp->successful()) {
                return '生圖失敗（HTTP '.$resp->status().'）：'.mb_substr($resp->body(), 0, 200);
            }
            $d = $resp->json('data.0') ?? [];
            $imgUrl = $d['url'] ?? '';
            $b64 = $d['b64_json'] ?? '';
            if ($imgUrl === '' && $b64 !== '') {
                // 回傳 base64 → 存成可公開存取的檔案
                $path = 'gen-images/'.Str::random(24).'.png';
                Storage::disk('public')->put($path, base64_decode($b64));
                $imgUrl = rtrim((string) config('app.url'), '/').'/storage/'.$path;
            }
            if ($imgUrl === '') {
                return '生圖回應沒有圖片資料。';
            }

            return "🖼️ 已生成圖片：{$imgUrl}";
        } catch (Throwable $e) {
            return '生圖失敗：'.$e->getMessage();
        }
    }
}
