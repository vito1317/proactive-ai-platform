<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Safety\Checkpoint;
use App\Pai\Settings\Settings;
use App\Pai\Skills\Skill;
use Throwable;

/** #5 還原最近一次（或多次）改檔/改設定。「還原剛才的修改」。 */
class RollbackSkill implements Skill
{
    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return 'rollback';
    }

    public function description(): string
    {
        return '還原最近的改檔/改設定（檢查點回滾）。count=還原幾筆（預設1）';
    }

    public function parameters(): array
    {
        return ['count' => '還原最近幾筆（預設 1）'];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $count = max(1, min(20, (int) ($args['count'] ?? 1)));
        $cps = Checkpoint::where('restored', false)->orderByDesc('id')->limit($count)->get();
        if ($cps->isEmpty()) {
            return '沒有可還原的檢查點。';
        }
        $done = [];
        foreach ($cps as $cp) {
            try {
                if ($cp->kind === 'file') {
                    if ($cp->existed) {
                        file_put_contents($cp->target, (string) $cp->before);
                        $done[] = "↩️ 還原檔案 {$cp->target}";
                    } else {
                        if (is_file($cp->target)) {
                            @unlink($cp->target);
                        }
                        $done[] = "🗑 移除新建檔 {$cp->target}";
                    }
                } else { // setting
                    if ($cp->existed) {
                        $this->settings->set($cp->target, $cp->before);
                    } else {
                        $this->settings->set($cp->target, null);
                    }
                    $done[] = "↩️ 還原設定 {$cp->target}";
                }
                $cp->update(['restored' => true]);
            } catch (Throwable $e) {
                $done[] = "⚠️ 還原 {$cp->target} 失敗：{$e->getMessage()}";
            }
        }

        return implode("\n", $done);
    }
}
