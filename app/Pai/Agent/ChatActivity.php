<?php

namespace App\Pai\Agent;

use App\Pai\Chat\Conversation;
use Illuminate\Support\Facades\Cache;

/**
 * 「對話中的 agent」即時活動心跳：SkillRunner 執行技能時把每一步寫進 Cache，
 * 讓 AgentOps 流程圖看得到網頁對話 / 語音 / TG / LINE / 通勤 / 自動化 / 主動思考的 agent 正在幹嘛。
 * 生命週期：建構時登記 → 每步 tick() 更新（TTL 續命）→ 執行結束（物件銷毀）自動移除；
 * 就算 process 異常死掉，TTL 到期也會自己消失，不會殘留幽靈 agent。
 */
class ChatActivity
{
    private const INDEX = 'pai:agentops:chat-index';

    private const TTL = 120; // 秒；每次 tick 續命

    private string $key;

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(Conversation $conv, string $goal)
    {
        $this->key = 'pai:agentops:chat:'.$conv->id;
        $this->data = [
            'conversation_id' => (int) $conv->id,
            'user_id' => (int) $conv->user_id,
            'source' => self::sourceOf($conv),
            'goal' => mb_substr(trim($goal), 0, 140),
            'steps' => [],
            'started_at' => now()->timestamp,
        ];
        $this->save();
        $ids = (array) Cache::get(self::INDEX, []);
        if (! in_array($conv->id, $ids, true)) {
            $ids[] = (int) $conv->id;
            Cache::put(self::INDEX, $ids, 3600);
        }
    }

    /** 記一步「正在做什麼」（同文字去重、保留最後 12 步）。 */
    public function tick(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $steps = $this->data['steps'];
        if (($steps[count($steps) - 1] ?? null) === $text) {
            return;
        }
        $steps[] = mb_substr($text, 0, 200);
        $this->data['steps'] = array_slice($steps, -12);
        $this->save();
    }

    public function finish(): void
    {
        Cache::forget($this->key);
    }

    public function __destruct()
    {
        $this->finish();
    }

    private function save(): void
    {
        Cache::put($this->key, $this->data, self::TTL);
    }

    /** 這條對話是哪種 agent（依通道推斷顯示名）。 */
    public static function sourceOf(Conversation $c): string
    {
        $sid = (string) ($c->voice_sid ?? '');

        return match (true) {
            str_starts_with($sid, 'commute:') => '通勤助理',
            str_starts_with($sid, 'automation:') => '自動化流程',
            str_starts_with($sid, 'proactive:') => '主動思考',
            str_starts_with($sid, 'voice') => '語音對話',
            $c->tg_chat_id !== null => 'Telegram 對話',
            $c->line_to !== null => 'LINE 對話',
            default => '網頁對話',
        };
    }

    /**
     * 讀出進行中的對話 agent 活動（uid 過濾；index 順手清掉已結束的）。
     *
     * @return list<array<string, mixed>>
     */
    public static function active(?int $uid): array
    {
        $out = [];
        $ids = (array) Cache::get(self::INDEX, []);
        $alive = [];
        foreach ($ids as $cid) {
            $d = Cache::get('pai:agentops:chat:'.$cid);
            if (! is_array($d)) {
                continue;
            }
            $alive[] = $cid;
            if ($uid !== null && (int) ($d['user_id'] ?? 0) !== $uid) {
                continue;
            }
            $out[] = $d;
        }
        if ($alive !== $ids) {
            Cache::put(self::INDEX, $alive, 3600);
        }

        return $out;
    }
}
