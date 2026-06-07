<?php

namespace App\Http\Controllers;

use App\Pai\Mcp\McpManager;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 讓節點上的 Gateway 自助註冊成 PAI 的 MCP server（搭配 cloudflared 通道自動接線）。
 * 用註冊密鑰驗證（gateway.register_secret，主控台才看得到）。
 */
class GatewayController extends Controller
{
    public function __construct(private readonly McpManager $manager, private readonly Settings $settings) {}

    /**
     * 配對碼：給 Android/其他節點「一鍵配對」用。回 base64({pai, token}) + QR 圖網址。
     * 需登入（中控台）才能取得——內含註冊 Token，不可公開。
     */
    public function pairCode(Request $request)
    {
        $payload = [
            "pai" => rtrim((string) config("app.url"), "/"),
            "token" => self::registerSecret(),
        ];
        $code = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $qr = "https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=12&data=".urlencode($code);

        // Android app 用 JSON；瀏覽器開 → 顯示 QR 圖 + 配對碼（掃描或複製）
        if ($request->wantsJson() || $request->query("format") === "json") {
            return response()->json(["code" => $code, "qr" => $qr, "pai" => $payload["pai"]]);
        }

        $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>PAI Gateway 配對</title>'
            .'<body style="font-family:system-ui;background:#0f172a;color:#e2e8f0;text-align:center;padding:24px">'
            .'<h2>📱 PAI Gateway 配對</h2>'
            .'<p>用 Android app「節點」分頁的<b>掃描配對</b>掃這個 QR：</p>'
            .'<img src="'.e($qr).'" style="background:#fff;padding:8px;border-radius:12px;width:320px;max-width:90vw">'
            .'<p style="margin-top:20px">或複製配對碼貼上：</p>'
            .'<textarea readonly onclick="this.select()" style="width:90%;max-width:520px;height:90px;background:#1e293b;color:#7dd3fc;border:1px solid #334155;border-radius:8px;padding:10px;font-size:12px">'.e($code).'</textarea>'
            .'<p style="color:#94a3b8;font-size:13px;margin-top:16px">PAI：'.e($payload["pai"]).'</p>'
            .'</body>';

        return response($html)->header("Content-Type", "text/html; charset=utf-8");
    }

    /** 取得（或產生）註冊密鑰。 */
    public static function registerSecret(): string
    {
        $s = app(Settings::class);
        $val = (string) $s->get('gateway.register_secret', '');
        if ($val === '') {
            $val = 'gwreg-'.Str::random(28);
            $s->set('gateway.register_secret', $val);
        }

        return $val;
    }

    /** 節點自助註冊：{name, url, secret}；header X-Register-Secret 驗證。 */
    public function register(Request $request): JsonResponse
    {
        $expected = self::registerSecret();
        if (! hash_equals($expected, (string) $request->header('X-Register-Secret'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'url' => ['required', 'string', 'max:512'],
            'secret' => ['nullable', 'string', 'max:256'],
        ]);

        // 名稱正規化成英數/dash（MCP 工具前綴用）
        $name = preg_replace('/[^a-z0-9_-]/i', '-', $data['name']) ?: 'node';
        $headers = $data['secret'] ? ['X-Gateway-Secret' => $data['secret']] : [];

        $res = $this->manager->add($name, $data['url'], $headers);

        return response()->json([
            'ok' => (bool) ($res['ok'] ?? false),
            'name' => $name,
            'message' => $res['message'] ?? '',
            'tools' => $res['tools'] ?? [],
        ], ($res['ok'] ?? false) ? 200 : 422);
    }
}
