<?php

namespace App\Console\Commands;

use App\Pai\Domains\DomainRegistry;
use Illuminate\Console\Command;

/**
 * 列出目前已載入的領域包（含被拒絕的檔案）。
 * 用法：php artisan pai:domains
 */
class PaiDomainsCommand extends Command
{
    protected $signature = 'pai:domains';

    protected $description = '列出已載入的主動式 AI 領域包 (Domain Packs)';

    public function handle(DomainRegistry $registry): int
    {
        $packs = $registry->all();

        if ($packs === []) {
            $this->warn('尚未載入任何領域包（檢查 config/pai.php 的 packs_path）。');
        } else {
            $this->info(sprintf('已載入 %d 個領域包：', count($packs)));
            $this->table(
                ['domain', 'autonomy', 'topology', '子智能體', '事件', '高風險工具'],
                array_map(static fn ($p): array => [
                    $p->domain,
                    $p->autonomy,
                    $p->topology,
                    count($p->roster),
                    count($p->eventTopics()),
                    count($p->highRiskTools()),
                ], array_values($packs)),
            );
        }

        $errors = $registry->errors();
        if ($errors !== []) {
            $this->newLine();
            $this->error(sprintf('%d 個領域包被拒絕：', count($errors)));
            foreach ($errors as $source => $errs) {
                $this->line("  <fg=red>{$source}</>");
                foreach ($errs as $e) {
                    $this->line("    - {$e}");
                }
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
