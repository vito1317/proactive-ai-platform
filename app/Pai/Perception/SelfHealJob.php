<?php

namespace App\Pai\Perception;

use App\Pai\Notify\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * #8 自我修復：定時檢查關鍵服務（本地 LLM、語音）。掛掉 → 嘗試重啟 + 通知；恢復 → 通知。
 */
class SelfHealJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    /** @var array<string,array{url:string,supervisor:string}> */
    private const SERVICES = [
        '本地 LLM (llama-server)' => ['url' => 'http://127.0.0.1:10003/health', 'supervisor' => ''],
        '語音 (MiniCPM-o)' => ['url' => 'http://127.0.0.1:8891/healthz', 'supervisor' => 'minicpm-o-voice'],
    ];

    public function handle(Notifier $notifier): void
    {
        foreach (self::SERVICES as $name => $svc) {
            $up = $this->ping($svc['url']);
            $key = 'pai:health:down:'.md5($name);
            $wasDown = Cache::get($key, false);

            if (! $up && ! $wasDown) {
                Cache::put($key, true, 86400);
                $restarted = $svc['supervisor'] !== '' ? $this->restart($svc['supervisor']) : false;
                $notifier->dispatch("⚠️ 服務異常：{$name} 沒回應。".($restarted ? '已嘗試自動重啟。' : '請手動檢查。'));
            } elseif ($up && $wasDown) {
                Cache::forget($key);
                $notifier->dispatch("✅ 服務恢復：{$name} 已正常。");
            }
        }
    }

    private function ping(string $url): bool
    {
        try {
            return Http::timeout(6)->get($url)->successful();
        } catch (Throwable) {
            // 部分服務沒有 health route → 連得上(任何回應)也算活著
            try {
                return Http::timeout(6)->get(preg_replace('#/[^/]*$#', '/', $url))->status() > 0;
            } catch (Throwable) {
                return false;
            }
        }
    }

    private function restart(string $unit): bool
    {
        foreach (["supervisorctl restart {$unit}", "sudo supervisorctl restart {$unit}"] as $cmd) {
            try {
                $p = Process::fromShellCommandline($cmd, timeout: 30);
                $p->run();
                if ($p->isSuccessful()) {
                    return true;
                }
            } catch (Throwable) {
            }
        }

        return false;
    }
}
