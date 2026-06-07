<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Skills\Skill;
use App\Pai\Skills\SkillRegistry;
use Throwable;

/**
 * execute-code：讓 AI 寫一小段 PHP「一次」串接多個工具 + 流程控制（迴圈/條件/組裝），
 * 把原本要好幾輪 LLM 來回的多步任務收成「一輪」——對本地慢模型大幅省時。
 *
 * 程式碼內可用：
 *   $tool('技能名', ['參數'=>值])   呼叫任何其他技能（含 mcp__節點__工具），回字串
 *   $say($任何值)                    輸出一行（最後一起回傳）
 *   return $值                        直接回傳結果
 * 高風險（能呼叫 run-shell 等）→ 受「允許系統自我修改」閘門控管。
 */
class ExecuteCodeSkill implements Skill
{
    public function __construct(private readonly SkillRegistry $registry) {}

    public function name(): string
    {
        return 'execute-code';
    }

    public function description(): string
    {
        return '寫一段 PHP 一次串接多個工具與流程（迴圈/條件/組裝），把多步任務收成一輪。程式內用 $tool(\'技能\',[參數]) 呼叫其他工具、$say(值) 輸出、return 回結果';
    }

    public function parameters(): array
    {
        return [
            'code' => 'PHP 程式碼（不含 <?php）。例：$w=$tool(\'web-search\',[\'query\'=>\'台中天氣\']); $say($w); return \'done\';',
        ];
    }

    public function isHighRisk(): bool
    {
        return true;
    }

    public function run(array $args): string
    {
        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            return '請提供 code。';
        }
        // 去掉誤帶的 PHP 開關標記與 markdown code fence
        $code = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $code);
        $code = preg_replace('/^<\\?php\s*/i', '', (string) $code);
        $code = (string) preg_replace('/\\?'.'>\s*$/', '', (string) $code);

        $registry = $this->registry;
        $buf = [];
        // 程式內可呼叫的工具 API
        $tool = function (string $name, array $a = []) use ($registry): string {
            $s = $registry->get($name);

            return $s ? (string) $s->run($a) : "（沒有工具：{$name}）";
        };
        $say = function ($x) use (&$buf): void {
            $buf[] = is_string($x) ? $x : json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };

        @set_time_limit(90);
        try {
            $ret = eval($code);
        } catch (Throwable $e) {
            return "execute-code 執行錯誤：{$e->getMessage()}\n（前面輸出）\n".mb_substr(implode("\n", $buf), 0, 2000);
        }

        $out = implode("\n", $buf);
        if (is_string($ret) && $ret !== '') {
            $out .= ($out !== '' ? "\n" : '').$ret;
        } elseif (is_array($ret)) {
            $out .= ($out !== '' ? "\n" : '').json_encode($ret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $out !== '' ? mb_substr($out, 0, 6000) : '（execute-code 完成，無輸出）';
    }
}
