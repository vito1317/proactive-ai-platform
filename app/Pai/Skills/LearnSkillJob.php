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

    public function __construct(public string $message, public array $obs) {}

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

        $prompt = <<<P
        以下是一次「成功完成」的任務軌跡。請把它萃取成一個【可重用的做法（playbook）】，讓未來遇到類似需求能照做。
        用台灣正體中文。若這次任務太瑣碎、一步就好、或沒有重用價值，name 回空字串。

        使用者需求：「{$this->message}」
        實際成功步驟：
        {$traj}

        只輸出 JSON：
        {"name":"白話技能名","when_to_use":"什麼情境用","steps":"濃縮成幾條關鍵步驟與要點(用哪些工具、順序、注意事項)","keywords":"命中關鍵字 空白分隔 3到6個"}
        /no_think
        P;

        try {
            $j = LlmClient::extractJson($llm->chat([['role' => 'user', 'content' => $prompt]], ['max_tokens' => 600]));
            $name = trim((string) ($j['name'] ?? ''));
            $steps = trim((string) ($j['steps'] ?? ''));
            if ($name === '' || mb_strlen($steps) < 5) {
                return;
            }
            $keywords = trim((string) ($j['keywords'] ?? ''));
            // 去重：同名或關鍵字高度重疊就更新，不重複建立
            $existing = LearnedSkill::where('name', $name)->first()
                ?? LearnedSkill::where('keywords', $keywords)->first();
            if ($existing) {
                $existing->update([
                    'when_to_use' => trim((string) ($j['when_to_use'] ?? $existing->when_to_use)),
                    'steps' => $steps, 'keywords' => $keywords !== '' ? $keywords : $existing->keywords,
                    'uses' => $existing->uses + 1,
                ]);

                return;
            }
            LearnedSkill::create([
                'name' => $name, 'when_to_use' => trim((string) ($j['when_to_use'] ?? '')),
                'steps' => $steps, 'keywords' => $keywords, 'uses' => 1,
            ]);
            // 上限：保留最近/最常用 150 筆
            if (LearnedSkill::count() > 150) {
                LearnedSkill::orderBy('uses')->orderBy('updated_at')->limit(LearnedSkill::count() - 150)->delete();
            }
        } catch (Throwable) {
            // 學習失敗不影響主流程
        }
    }
}
