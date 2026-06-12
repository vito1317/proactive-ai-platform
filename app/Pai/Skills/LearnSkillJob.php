<?php

namespace App\Pai\Skills;

use App\Pai\Cognition\LlmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 自我改進：一次成功的多步任務後，把「成功的做法」萃取成可重用的 playbook 存起來。
 * 下次遇到類似需求，SkillRunner 會把它注入提示讓 agent 照做（越用越快越穩）。
 * 背景執行，不影響回覆延遲。
 *
 * @param  list<array{action:string,args:array,result:string}>  $obs
 */
class LearnSkillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public string $message, public array $obs, public ?int $userId = null) {}

    public function handle(LlmClient $llm): void
    {
        // 只從「真的用了多個工具、且大多成功」的軌跡學習
        $steps = collect($this->obs)->filter(fn ($o) => ! str_starts_with((string) ($o['result'] ?? ''), '未知工具'));
        if ($steps->count() < 2) {
            return;
        }
        $traj = $steps->map(function ($o, $i) {
            $a = json_encode($o['args'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $r = (string) preg_replace('/\[\[IMG\]\]data:image\/[a-z]+;base64,[A-Za-z0-9+\/=]+/', '（截圖）', (string) $o['result']);

            return ($i + 1).'. '.$o['action'].'('.$a.') → '.mb_substr($r, 0, 200);
        })->implode("\n");

        $prompt = \App\Pai\Cognition\Prompts::render('learn-skill', ['message' => $this->message, 'trajectory' => $traj]);

        try {
            $j = $llm->chatJson([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 600, 'tier' => 'small']);
            $name = mb_substr(trim((string) ($j['name'] ?? '')), 0, 50);
            $steps = mb_substr(trim((string) ($j['steps'] ?? '')), 0, 2000);
            if ($name === '' || mb_strlen($steps) < 5) {
                return;
            }
            // keywords 正規化：逗號/頓號當分隔、去標點、每個 ≤24 字、最多 6 個——
            // 模型沒照「空白分隔」格式時也能修復，否則之後 relevant() 比對命中率會爛掉
            $kwTokens = preg_split('/[\s,，、;；]+/u', trim((string) ($j['keywords'] ?? ''))) ?: [];
            $kwTokens = array_values(array_filter(array_map(
                fn ($t) => mb_substr(trim((string) preg_replace('/[「」『』"\'`!?！？。.]+/u', '', (string) $t)), 0, 24),
                $kwTokens,
            )));
            $keywords = implode(' ', array_slice($kwTokens, 0, 6));
            // 去重：同名或關鍵字高度重疊就更新，不重複建立（限同一擁有者，租戶隔離）
            $existing = LearnedSkill::where('user_id', $this->userId)->where('name', $name)->first()
                ?? LearnedSkill::where('user_id', $this->userId)->where('keywords', $keywords)->first();
            if ($existing) {
                $existing->update([
                    'when_to_use' => mb_substr(trim((string) ($j['when_to_use'] ?? $existing->when_to_use)), 0, 200),
                    'steps' => $steps, 'keywords' => $keywords !== '' ? $keywords : $existing->keywords,
                    'uses' => $existing->uses + 1,
                ]);

                return;
            }
            LearnedSkill::create([
                'name' => $name, 'when_to_use' => mb_substr(trim((string) ($j['when_to_use'] ?? '')), 0, 200),
                'steps' => $steps, 'keywords' => $keywords, 'uses' => 1, 'user_id' => $this->userId,
            ]);
            // 上限：每位擁有者保留最近/最常用 150 筆
            $cnt = LearnedSkill::where('user_id', $this->userId)->count();
            if ($cnt > 150) {
                LearnedSkill::where('user_id', $this->userId)->orderBy('uses')->orderBy('updated_at')->limit($cnt - 150)->delete();
            }
        } catch (Throwable) {
            // 學習失敗不影響主流程
        }
    }
}
