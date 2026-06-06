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
    public function pairCode(Request $request): JsonResponse
    {
        $payload = [
            "pai" => rtrim((string) config("app.url"), "/"),
            "token" => self::registerSecret(),
        ];
        $code = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $qr = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=".urlencode($code);

        return response()->json(["code" => $code, "qr" => $qr, "pai" => $payload["pai"]]);
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
