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
