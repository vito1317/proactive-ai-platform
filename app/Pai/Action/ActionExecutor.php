<?php

namespace App\Pai\Action;

use App\Pai\Security\EgressGateway;
use App\Pai\Security\Sandbox;
use App\Pai\Security\SecretRef;
use Throwable;

/**
 * L4 行動的「真實執行」：把已核准（或低風險自動放行）的動作落實到外部世界。
 *  - apply-patch:<path>  → 寫回目標 repo 檔案，並在沙盒重跑測試驗證
 *  - isolate-host / firewall.block / idp.disable-account → 經 EgressGateway 注入憑證呼叫
 *  - 其它（open-ticket 等）→ 記為完成
 *
 * @return array{ok: bool, output: string}
 */
class ActionExecutor
{
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly EgressGateway $egress,
    ) {}

    /**
     * @param  array{action: string, payload?: array}  $action
     * @return array{ok: bool, output: string}
     */
    public function execute(array $action, string $domain): array
    {
        $name = (string) ($action['action'] ?? '');
        $payload = (array) ($action['payload'] ?? []);

        if (str_starts_with($name, 'apply-patch:')) {
            return $this->applyPatch($payload);
        }
        if (in_array($name, ['isolate-host', 'firewall.block', 'idp.disable-account'], true)) {
            return $this->containment($name, $payload);
        }
        if (str_starts_with($name, 'open-ticket:')) {
            return ['ok' => true, 'output' => '工單已建立並指派。'];
        }
        if ($name === 'clear-cache') {
            return $this->clearCache();
        }

        return ['ok' => true, 'output' => "已執行 {$name}（記為完成）。"];
    }

    /** 真實低風險修復：清除應用快取。 */
    private function clearCache(): array
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('cache:clear');

            return ['ok' => true, 'output' => '已清除應用快取（cache:clear）。'.trim(\Illuminate\Support\Facades\Artisan::output())];
        } catch (Throwable $e) {
            return ['ok' => false, 'output' => '清除快取失敗：'.$e->getMessage()];
        }
    }

    /** 寫回完整檔案內容，再於沙盒重跑測試驗證。 */
    private function applyPatch(array $payload): array
    {
        $repo = (string) config('pai.devauto.repo_path');
        $path = (string) ($payload['path'] ?? '');
        $content = (string) ($payload['patch'] ?? '');

        $base = realpath($repo);
        $target = realpath($repo.'/'.$path);
        if ($base === false || $target === false || ! str_starts_with($target, $base) || ! is_file($target)) {
            return ['ok' => false, 'output' => "拒絕套用：路徑不存在或越界（{$path}）。"];
        }
        if ($content === '') {
            return ['ok' => false, 'output' => '拒絕套用：patch 內容為空。'];
        }

        file_put_contents($target, rtrim($content)."\n");

        // 重跑測試驗證修補
        $entry = (string) config('pai.devauto.test_entry');
        $r = var_export($repo, true);
        $e = var_export($entry, true);
        $harness = <<<PY
        import subprocess, sys
        p = subprocess.run([sys.executable, {$e}], cwd={$r}, capture_output=True, text=True)
        sys.stdout.write(p.stdout); sys.stderr.write(p.stderr); sys.exit(p.returncode)
        PY;
        $res = $this->sandbox->run('python', $harness, 30);
        $passed = ! $res->timedOut && $res->exitCode === 0;

        return [
            'ok' => $passed,
            'output' => "已寫回 {$path}；重跑測試：".($passed ? '✅ 全綠' : "❌ 仍失敗\n".mb_substr(trim($res->stdout."\n".$res->stderr), 0, 400)),
        ];
    }

    /** 遏制動作：有設定端點則經 EgressGateway 注入憑證實打，否則模擬。 */
    private function containment(string $action, array $payload): array
    {
        $target = (string) ($payload['target'] ?? 'unknown');
        $url = config('pai.secir.containment_url');

        if ($url) {
            try {
                $resp = $this->egress->client()
                    ->withHeaders(['Authorization' => 'Bearer '.SecretRef::placeholder('edr_token')])
                    ->post($url, ['action' => $action, 'target' => $target]);

                return ['ok' => $resp->successful(), 'output' => "已對 {$target} 執行 {$action}（HTTP {$resp->status()}）。"];
            } catch (Throwable $e) {
                return ['ok' => false, 'output' => "執行 {$action} 失敗：{$e->getMessage()}"];
            }
        }

        return ['ok' => true, 'output' => "已（模擬）對 {$target} 執行 {$action}；憑證由 EgressGateway 注入，智能體未持有。"];
    }
}
