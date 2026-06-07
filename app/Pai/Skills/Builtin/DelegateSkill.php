<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\DelegateSubtaskJob;
use App\Pai\Skills\Skill;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * #4 多代理委派：把大任務拆成多個子任務「並行」交給子代理（各自獨立 context），
 * 全部跑完彙整。對本地慢模型能用並行省時（例：同時查資料＋排行程）。
 */
class DelegateSkill implements Skill
{
    public function name(): string
    {
        return 'delegate';
    }

    public function description(): string
    {
        return '把大任務拆成多個獨立子任務，並行交給子代理同時做，再彙整結果。適合彼此不相依、可同時進行的子任務（如同時查 A 和查 B）';
    }

    public function parameters(): array
    {
        return ['tasks' => '子任務清單（陣列；或用換行/分號分隔的字串），每個是一句可獨立完成的指令'];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $raw = $args['tasks'] ?? '';
        $tasks = is_array($raw) ? $raw : preg_split('/[\n;；]+/u', (string) $raw);
        $tasks = array_values(array_filter(array_map(fn ($t) => trim((string) $t), $tasks)));
        if (count($tasks) < 1) {
            return '請提供至少一個子任務。';
        }
        $tasks = array_slice($tasks, 0, 6); // 並行上限 6
        $batch = (string) Str::uuid();
        $uid = \App\Pai\Chat\Conversation::query()->value('user_id');
        foreach ($tasks as $i => $task) {
            Cache::forget("delegate:{$batch}:{$i}");
            DelegateSubtaskJob::dispatch($batch, $i, $task, $uid);
        }

        // 輪詢等所有子任務完成（最多 ~200 秒）
        $deadline = microtime(true) + 200;
        $results = array_fill(0, count($tasks), null);
        while (microtime(true) < $deadline) {
            $allDone = true;
            foreach ($tasks as $i => $_) {
                if ($results[$i] === null) {
                    $r = Cache::get("delegate:{$batch}:{$i}");
                    if ($r !== null) {
                        $results[$i] = $r;
                    } else {
                        $allDone = false;
                    }
                }
            }
            if ($allDone) {
                break;
            }
            usleep(500_000);
        }

        $out = ["🧩 已並行完成 ".count($tasks)." 個子任務："];
        foreach ($tasks as $i => $task) {
            $r = $results[$i] ?? '（逾時未完成）';
            $out[] = "\n【子任務 ".($i + 1)."】{$task}\n{$r}";
        }

        return implode("\n", $out);
    }
}
