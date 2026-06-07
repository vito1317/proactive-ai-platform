<?php

namespace App\Pai\Skills;

use App\Pai\Chat\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * #4 多代理：一個子任務 = 一個隔離的子代理（自己的 conversation/context），
 * 跑完把結果寫進 Cache 給父代理彙整。多個 job 由 queue workers 並行執行。
 */
class DelegateSubtaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $batchId, public int $index, public string $task, public ?int $userId = null) {}

    public function handle(SkillRunner $runner): void
    {
        $result = '';
        try {
            $conv = Conversation::create(['user_id' => $this->userId, 'title' => '子代理：'.mb_substr($this->task, 0, 20)]);
            $r = $runner->handle($conv, $this->task);
            $result = trim((string) ($r['reply'] ?? ''));
        } catch (Throwable $e) {
            $result = '（子任務失敗：'.$e->getMessage().'）';
        }
        Cache::put("delegate:{$this->batchId}:{$this->index}", $result !== '' ? $result : '（無輸出）', 900);
    }
}
