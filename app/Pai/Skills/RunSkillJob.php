<?php

namespace App\Pai\Skills;

use App\Pai\Chat\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 背景執行較重（LLM 生成型）的技能，完成後把結果寫回對話。
 * 避免在 SSE 請求內同步跑數分鐘的生成 → 阻塞模型、斷線即遺失。
 */
class RunSkillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $conversationId, public string $skill, public array $args) {}

    public function handle(SkillRegistry $registry): void
    {
        $conv = Conversation::find($this->conversationId);
        $skill = $registry->get($this->skill);
        if (! $conv || ! $skill) {
            return;
        }
        try {
            $reply = $skill->run($this->args);
            $meta = ['category' => 'skill', 'skill' => $this->skill];
        } catch (Throwable $e) {
            $reply = "執行「{$this->skill}」時發生錯誤：".$e->getMessage();
            $meta = ['category' => 'skill', 'error' => true];
        }
        $conv->addMessage('assistant', $reply, $meta);
    }
}
