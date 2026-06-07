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
    public static ?SkillRegistry $ctxRegistry = null;

    /** @var array<int,string> */
    public static array $ctxBuf = [];

    public function __construct(private readonly SkillRegistry $registry) {}

    public function name(): string
    {
        return 'execute-code';
    }

    public function description(): string
    {
        return '寫一段 PHP 一次串接多個工具與流程（迴圈/條件/組裝），把多步任務收成一輪。程式內用 tool(\'技能\',[參數]) 呼叫其他工具、say(值) 輸出、return 回結果（tool/say 是全域函式，在迴圈/匿名函式裡都能直接用）';
    }

    public function parameters(): array
    {
        return [
            'code' => 'PHP 程式碼（不含開頭標記）。例：$w = tool(\'web-search\', [\'query\'=>\'台中天氣\']); say($w); return \'done\';',
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

        // #6 加固：擋掉危險的直接呼叫，逼程式只能透過 $tool 編排（$tool/run-shell 本身已受閘門）。
        // 需要系統操作就呼叫 $tool('run-shell', [...])，會留紀錄、受控管。
        $banned = ['system', 'exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
            'eval', 'assert', 'unlink', 'rmdir', 'fwrite', 'fopen', 'file_put_contents', 'rename',
            'putenv', 'symlink', 'mail', 'curl_exec', 'fsockopen', 'extract'];
        foreach ($banned as $fn) {
            if (preg_match('/(?<![A-Za-z0-9_>$])'.preg_quote($fn, '/').'\s*\(/i', $code)
                || preg_match('/`/', $code)) {
                return "為安全起見，execute-code 不允許直接呼叫系統/檔案函式（如 {$fn}、反引號）。"
                    ."需要系統操作請在程式內用 \$tool('run-shell', ['command'=>'...']) 或對應工具，會受控管。";
            }
        }

        // 用全域函式 tool()/say()（也保留 $tool/$say 變數）——全域函式在巢狀閉包/匿名函式裡也能呼叫，
        // 避免 LLM 在 array_map(function(){ ... $tool ... }) 這類寫法裡因未 use 捕捉而「Undefined variable $tool」。
        ExecuteCodeSkill::$ctxRegistry = $this->registry;
        ExecuteCodeSkill::$ctxBuf = [];
        if (! function_exists('tool')) {
            eval('function tool($name, array $a = []) { $s = \App\Pai\Skills\Builtin\ExecuteCodeSkill::$ctxRegistry?->get($name); return $s ? (string) $s->run($a) : "（沒有工具：$name）"; }');
        }
        if (! function_exists('say')) {
            eval('function say($x) { \App\Pai\Skills\Builtin\ExecuteCodeSkill::$ctxBuf[] = is_string($x) ? $x : json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }');
        }
        $registry = $this->registry;
        $buf = &ExecuteCodeSkill::$ctxBuf;
        $tool = fn (string $name, array $a = []): string => tool($name, $a);   // 變數形相容
        $say = fn ($x) => say($x);

        @set_time_limit(90);
        try {
            $ret = eval($code);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('execute-code 失敗', ['err' => $e->getMessage()]);

            return "execute-code 執行錯誤：{$e->getMessage()}\n（前面輸出）\n".mb_substr(implode("\n", ExecuteCodeSkill::$ctxBuf), 0, 2000);
        }

        $out = implode("\n", ExecuteCodeSkill::$ctxBuf);
        if (is_string($ret) && $ret !== '') {
            $out .= ($out !== '' ? "\n" : '').$ret;
        } elseif (is_array($ret)) {
            $out .= ($out !== '' ? "\n" : '').json_encode($ret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $out !== '' ? mb_substr($out, 0, 6000) : '（execute-code 完成，無輸出）';
    }
}
