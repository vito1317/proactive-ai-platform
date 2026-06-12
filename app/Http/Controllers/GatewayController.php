<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Pai\Mcp\McpManager;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * 解析 gateway 憑證 → 擁有者帳號。
     * 1) per-device token（QR 配對換得，存 device_tokens.token_hash）→ 該裝置帳號（順手更新 last_seen）
     * 2) 舊版共用 register_secret → admin（向後相容）
     * 回 null = 無效憑證。
     */
    public static function resolveOwner(?string $token): ?User
    {
        $token = (string) $token;
        if ($token === '') {
            return null;
        }
        $row = DB::table('device_tokens')->where('token_hash', hash('sha256', $token))->first();
        if ($row) {
            DB::table('device_tokens')->where('id', $row->id)->update(['last_seen_at' => now()]);

            return User::find($row->user_id);
        }
        if (hash_equals(self::registerSecret(), $token)) {
            return User::where('role', 'admin')->orderBy('id')->first();
        }

        return null;
    }

    /** 此次請求的有效節點憑證（device token 或共用 secret）對應的帳號；null=未授權。 */
    public static function ownerFromRequest(Request $request): ?User
    {
        return self::resolveOwner((string) $request->header('X-Register-Secret'));
    }

    /**
     * 產生「一次性配對碼」(綁目前登入帳號，10 分鐘有效) + QR。
     * 手機掃碼 → POST /api/gateway/pair 兌換成該帳號的長期 per-device 憑證。
     */
    public function pairCreate(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        // admin 可指定要把裝置綁到哪個帳號；一般使用者只能綁自己
        $targetId = $user->id;
        if ($user->isAdmin() && $request->filled('user_id')) {
            $targetId = (int) $request->input('user_id');
        }
        $pt = 'pt-'.Str::random(32);
        Cache::put('pair:'.$pt, $targetId, 600);
        $payload = ['pai' => rtrim((string) config('app.url'), '/'), 'pair' => $pt];
        $code = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return response()->json([
            'code' => $code,
            'qr' => 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=12&data='.urlencode($code),
            'pai' => $payload['pai'],
            'expires_in' => 600,
        ]);
    }

    /**
     * 兌換配對碼 → 長期 per-device 憑證（綁到配對碼所屬帳號）。
     * body: {pair_token, name, tools?}；pair_token 即是這次的授權，不需 header。
     */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pair_token' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:64'],
            'tools' => ['nullable', 'array'],
        ]);
        $userId = Cache::get('pair:'.$data['pair_token']);
        if (! $userId || ! ($user = User::find($userId))) {
            return response()->json(['error' => 'invalid_or_expired'], 401);
        }
        Cache::forget('pair:'.$data['pair_token']); // 一次性

        $name = preg_replace('/[^a-z0-9_-]/i', '-', $data['name']) ?: 'node';
        $deviceToken = 'dev-'.Str::random(40);
        DB::table('device_tokens')->updateOrInsert(
            ['name' => $name, 'user_id' => $user->id],
            ['token_hash' => hash('sha256', $deviceToken), 'last_seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
        // 先建/更新該裝置的 McpServer 並綁定擁有者
        $tools = $data['tools'] ?? [];
        \App\Pai\Mcp\McpServer::updateOrCreate(['name' => $name], [
            'url' => 'reverse://'.$name, 'headers' => [], 'enabled' => true,
            'tools' => $tools, 'user_id' => $user->id, 'last_error' => null,
        ]);
        if ($tools) {
            \App\Pai\Mcp\ReverseBus::setTools($name, $tools);
        }

        return response()->json([
            'ok' => true,
            'device_token' => $deviceToken,        // 手機存起來當之後所有請求的憑證
            'name' => $name,
            'account' => $user->email,
            'pai' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    /** 節點自助註冊：{name, url, secret}；header X-Register-Secret 驗證（device token 或共用 secret）。 */
    public function register(Request $request): JsonResponse
    {
        $owner = self::ownerFromRequest($request);
        if ($owner === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:512'],
            'secret' => ['nullable', 'string', 'max:256'],
            'mode' => ['nullable', 'string', 'in:http,reverse'],
            'tools' => ['nullable', 'array'],
        ]);

        // 名稱正規化成英數/dash（MCP 工具前綴用）
        $name = preg_replace('/[^a-z0-9_-]/i', '-', $data['name']) ?: 'node';

        // 反向節點（手機等無公網/無法被連入）：不測連線，工具清單由節點帶上，之後走 ReverseBus
        if (($data['mode'] ?? '') === 'reverse') {
            $tools = $data['tools'] ?? [];
            \App\Pai\Mcp\ReverseBus::setTools($name, $tools);
            \App\Pai\Mcp\McpServer::updateOrCreate(['name' => $name], [
                'url' => 'reverse://'.$name, 'headers' => [], 'enabled' => true,
                'tools' => $tools, 'user_id' => $owner->id, 'last_error' => null,
            ]);

            return response()->json(['ok' => true, 'name' => $name, 'mode' => 'reverse',
                'message' => "反向節點「{$name}」已接入（".count($tools).' 個工具）', 'tools' => $tools]);
        }

        $headers = $data['secret'] ? ['X-Gateway-Secret' => $data['secret']] : [];
        $res = $this->manager->add($name, (string) ($data['url'] ?? ''), $headers);

        return response()->json([
            'ok' => (bool) ($res['ok'] ?? false),
            'name' => $name,
            'message' => $res['message'] ?? '',
            'tools' => $res['tools'] ?? [],
        ], ($res['ok'] ?? false) ? 200 : 422);
    }

    /** 反向節點 long-poll：取一個待執行的工具呼叫（最多等 25 秒）。回 {call:null} 表示沒有，節點應立即重新 poll。 */
    public function poll(Request $request): JsonResponse
    {
        if (self::ownerFromRequest($request) === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $node = preg_replace('/[^a-z0-9_-]/i', '-', (string) $request->query('node')) ?: 'node';
        $call = \App\Pai\Mcp\ReverseBus::next($node);

        return response()->json(['call' => $call]);
    }

    /** 反向節點回傳某次呼叫的執行結果。 */
    public function result(Request $request): JsonResponse
    {
        if (self::ownerFromRequest($request) === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $request->validate(['id' => ['required', 'string'], 'text' => ['nullable', 'string']]);
        \App\Pai\Mcp\ReverseBus::submit($data['id'], (string) ($data['text'] ?? ''));

        return response()->json(['ok' => true]);
    }
}
