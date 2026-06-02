<?php

namespace App\Console\Commands;

use App\Pai\Cognition\CognitiveEngine;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Perception\PaiEvent;
use Illuminate\Console\Command;

/**
 * 直接（同步）對一個事件跑 L3 認知迴圈，印出完整軌跡。除錯/示範用。
 * 用法：php artisan pai:think {event_id}
 */
class PaiThinkCommand extends Command
{
    protected $signature = 'pai:think {event : pai_events.id}';

    protected $description = '對一個事件直接執行 L3 認知大腦並印出推理軌跡';

    public function handle(DomainRegistry $registry, CognitiveEngine $engine): int
    {
        $event = PaiEvent::find($this->argument('event'));
        if ($event === null) {
            $this->error('找不到事件。');

            return self::FAILURE;
        }

        $pack = $registry->get((string) $event->domain);
        if ($pack === null) {
            $this->error("事件未路由到任何領域（domain=".($event->domain ?? 'null').'）。');

            return self::FAILURE;
        }

        $this->info("協調者 {$pack->coordinator} 正在處理事件 #{$event->id}（{$event->topic}）…");
        $run = $engine->run($event, $pack);

        $this->newLine();
        foreach ($run->steps as $s) {
            $this->line("<fg=cyan>[{$s['step']}]</> <comment>{$s['action']}</> ".json_encode($s['action_input'], JSON_UNESCAPED_UNICODE));
            if (! empty($s['thought'])) {
                $this->line("    💭 {$s['thought']}");
            }
            $this->line("    👁  ".str_replace("\n", ' ', mb_substr($s['observation'], 0, 160)));
        }

        $this->newLine();
        $this->line('<comment>發現：</comment>');
        foreach ($run->findings as $f) {
            $this->line("  • {$f}");
        }
        $this->line('<comment>動作：</comment>');
        foreach ($run->actions as $a) {
            $this->line("  ⚡ {$a['action']} [risk={$a['risk']}] → <info>{$a['status']}</info>");
        }
        $this->newLine();
        $this->line("總結：{$run->summary}");
        $this->line("狀態：<info>{$run->status->value}</info> · tokens={$run->tokens}");

        return self::SUCCESS;
    }
}
