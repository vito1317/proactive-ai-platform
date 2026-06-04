<?php

namespace App\Console\Commands;

use App\Pai\Cognition\LlmClient;
use App\Pai\Cognition\LlmException;
use Illuminate\Console\Command;

/**
 * 對 L3 LLM 後端做即時連線測試。
 * 用法：php artisan pai:llm-ping
 */
class PaiLlmPingCommand extends Command
{
    protected $signature = 'pai:llm-ping {prompt=用一句話說明你是什麼模型}';

    protected $description = '測試與 LLM 後端 (llama-server) 的連線';

    public function handle(LlmClient $llm): int
    {
        $this->line('後端：<info>'.config('pai.llm.base_url').'</info>');
        try {
            $reply = $llm->chat([
                ['role' => 'user', 'content' => (string) $this->argument('prompt')],
            ]);
        } catch (LlmException $e) {
            $this->error('連線失敗：'.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<comment>模型回覆：</comment>');
        $this->line(trim($reply));

        return self::SUCCESS;
    }
}
