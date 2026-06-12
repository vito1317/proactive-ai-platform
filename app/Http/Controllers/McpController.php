<?php

namespace App\Http\Controllers;

use App\Pai\Mcp\McpManager;
use App\Pai\Mcp\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MCP 伺服器管理（per-account）：每個帳號管理自己能存取的 MCP 節點，可新增 HTTP MCP、移除自己的、測連線。
 * admin 看全部；一般帳號看自己擁有/被授權的。新增的 MCP 歸屬該帳號。
 */
class McpController extends Controller
{
    public function __construct(private readonly McpManager $manager) {}

    public function index(Request $request): Response
    {
        $u = $request->user();
        $allowed = ($u && ! $u->isAdmin()) ? $u->allowedDeviceNames() : null;
        $servers = McpServer::orderBy('name')->get()
            ->when($allowed !== null, fn ($c) => $c->filter(fn ($s) => in_array($s->name, $allowed, true)))
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'url' => $s->url,
                'reverse' => str_starts_with((string) $s->url, 'reverse://'),
                'enabled' => (bool) $s->enabled,
                'tools' => is_array($s->tools) ? count($s->tools) : 0,
                'mine' => $s->user_id === $u?->id,
                'last_error' => $s->last_error,
            ])->values();

        return Inertia::render('Agent/Mcp', ['servers' => $servers, 'isAdmin' => $u?->isAdmin() ?? false]);
    }

    public function store(Request $request): RedirectResponse
    {
        $u = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'url' => ['required', 'string', 'max:512'],
            'secret' => ['nullable', 'string', 'max:256'],
        ]);
        $name = preg_replace('/[^a-z0-9_-]/i', '-', $data['name']) ?: 'mcp';
        $headers = $data['secret'] ? ['Authorization' => 'Bearer '.$data['secret']] : [];
        $res = $this->manager->add($name, $data['url'], $headers);
        // 歸屬到新增的帳號（per-account 隔離）
        McpServer::where('name', $name)->update(['user_id' => $u?->id]);

        return back()->with('flash', ($res['ok'] ?? false)
            ? ['success' => "已接入 MCP「{$name}」（".count($res['tools'] ?? [])." 個工具）"]
            : ['error' => 'MCP 接入失敗：'.($res['message'] ?? '連線錯誤')]);
    }

    public function test(Request $request, McpServer $server): JsonResponse
    {
        $this->authorizeServer($request, $server);

        return response()->json($this->manager->test($server->name));
    }

    public function destroy(Request $request, McpServer $server): RedirectResponse
    {
        $this->authorizeServer($request, $server);
        $this->manager->remove($server->name);

        return back()->with('flash', ['success' => "已移除 MCP「{$server->name}」"]);
    }

    /** 只有 admin 或擁有者能操作該 MCP。 */
    private function authorizeServer(Request $request, McpServer $server): void
    {
        $u = $request->user();
        abort_unless($u && ($u->isAdmin() || $server->user_id === $u->id), 403, '只能管理自己的 MCP');
    }
}
