<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Cognition\LlmClient;
use App\Pai\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * 全雙工語音的「指揮大腦」入口：voice_server (:8891) 在每一輪把使用者語音轉成
 * 文字後 POST 到這裡，本端用與聊天室完全相同的 agentic 技能引擎處理（可實際操控
 * 系統），回傳要朗讀的文字 + 活動步驟。語音因此成為「能操控系統」的另一個頻道。
 *
 * 用共用密鑰（X-Voice-Secret）驗證，因為 voice_server 不是登入使用者。
 */
class VoiceAgentController extends Controller
{
    public function __construct(
        private readonly ChatResponder $responder,
        private readonly LlmClient $llm,
        private readonly Settings $settings,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // 共用密鑰驗證（優先讀 Settings → 可由 AI / 後台即時調整，退回 config 預設）
        $secret = (string) $this->settings->get('voice.agent_secret', config('services.voice.agent_secret'));
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Voice-Secret'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'session' => ['nullable', 'string', 'max:128'],
        ]);

        $transcript = trim($data['transcript']);
        if ($transcript === '') {
            return response()->json(['reply' => '', 'steps' => [], 'conversation_id' => $data['conversation_id'] ?? null]);
        }

        $conv = $this->resolveConversation($data['conversation_id'] ?? null, $data['session'] ?? null);
        $conv->addMessage('user', $transcript, ['source' => 'voice']);

        // 直達指令：明確的「打開/開啟 X」直接跑 open-app，不繞 LLM（快又不會被反問）
        if ($direct = $this->directCommand($transcript)) {
            $conv->addMessage('assistant', $direct['reply'], array_merge($direct['meta'], ['source' => 'voice']));

            return response()->json(['reply' => $direct['reply'], 'steps' => [$direct['step'] ?? '⚡ 直接執行'], 'meta' => $direct['meta'], 'conversation_id' => $conv->id]);
        }

        // 用與 SSE / TG / LINE 相同的路由 → 可閒聊也可實際跑技能操控系統
        $steps = [];
        $onStep = function (string $t) use (&$steps) {
            $steps[] = $t;
        };

        try {
            $r = $this->responder->route($conv, $transcript, $onStep);
            $reply = $r['stream']
                ? trim($this->llm->chat($r['messages']))
                : (string) $r['reply'];
            $meta = $r['stream'] ? ['category' => 'chat'] : ($r['meta'] ?? []);
        } catch (Throwable $e) {
            $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
            $meta = ['error' => true];
        }

        if ($reply === '') {
            $reply = '我沒有產生回覆，請再說一次。';
        }

        $conv->addMessage('assistant', $reply, array_merge($meta, ['source' => 'voice', 'trace' => $steps]));

        return response()->json([
            'reply' => $reply,
            'steps' => $steps,
            'meta' => $meta,
            'conversation_id' => $conv->id,
        ]);
    }

    /**
     * 直達指令：不經 LLM，直接把明確的語音命令對應到技能執行。
     * 目前支援「打開/開啟/啟動 <程式>」→ open-app。回 null 表示非直達指令、走一般 agentic。
     *
     * @return array{reply:string,meta:array,step?:string}|null
     */
    private function directCommand(string $transcript): ?array
    {
        $t = trim($transcript);
        // 開啟程式：打開/開啟/啟動/打开/开启/启动/開一下/幫我開 + 目標
        if (preg_match('/^\s*(?:幫我|帮我|請|请)?\s*(?:打開|打开|開啟|开启|啟動|启动|開一下|开一下|開|开|執行|执行|run|open|launch|start)\s*[一個個了]*\s*(.+?)\s*[。.!！?？]*\s*$/iu', $t, $m)) {
            $target = trim($m[1]);
            // 去掉常見贅詞
            $target = preg_replace('/(這個|這|那個|程式|程序|應用程式|应用|app|軟體|软件|瀏覽器|浏览器)$/u', '', trim($target));
            $cmd = $this->resolveAppCommand($target !== '' ? $target : $m[1]);
            if ($cmd === null) {
                return null;
            }
            $skill = app(\App\Pai\Skills\SkillRegistry::class)->get('open-app');
            if (! $skill) {
                return null;
            }
            $result = $skill->run(['command' => $cmd]);

            return [
                'reply' => "好，已幫你開啟「{$target}」。{$result}",
                'meta' => ['category' => 'skill', 'skill' => 'open-app', 'direct' => true, 'command' => $cmd],
                'step' => "🚀 直接啟動：{$cmd}",
            ];
        }

        return null;
    }

    /** 把口語的程式名對應成可執行指令；對不到常見清單就用清理後的名字本身。 */
    private function resolveAppCommand(string $name): ?string
    {
        $n = mb_strtolower(trim($name));
        if ($n === '') {
            return null;
        }
        // 瀏覽器一律用本機實際裝的（server 有 chromium-browser，沒有 google-chrome）；
        // 用 sh 候選串：有 google-chrome 就用，否則退 chromium-browser/chromium
        $browser = "sh -c 'command -v google-chrome >/dev/null && exec google-chrome || command -v chromium-browser >/dev/null && exec chromium-browser || exec chromium'";
        $map = [
            'chrome' => $browser, 'google chrome' => $browser, 'google' => $browser,
            'googlechrome' => $browser, '谷歌' => $browser, '瀏覽器' => $browser, '浏览器' => $browser,
            'chromium' => 'chromium-browser',
            'firefox' => 'firefox', '火狐' => 'firefox',
            'edge' => 'microsoft-edge', 'safari' => $browser,
            'terminal' => 'gnome-terminal', '終端' => 'gnome-terminal', '終端機' => 'gnome-terminal', '终端' => 'gnome-terminal',
            'vscode' => 'code', 'vs code' => 'code', 'code' => 'code', '編輯器' => 'code',
            'calculator' => 'gnome-calculator', '計算機' => 'gnome-calculator', '计算器' => 'gnome-calculator',
            'files' => 'nautilus', '檔案' => 'nautilus', '文件管理' => 'nautilus', '檔案總管' => 'nautilus',
            'settings' => 'gnome-control-center', '設定' => 'gnome-control-center', '设置' => 'gnome-control-center',
            'gedit' => 'gedit', '記事本' => 'gedit', '文字編輯' => 'gedit',
        ];
        foreach ($map as $k => $v) {
            if (str_contains($n, $k)) {
                return $v;
            }
        }
        // 對不到 → 用清理後的名字當指令（去除空白、只留安全字元）
        $clean = preg_replace('/[^a-z0-9_.\- ]/i', '', $name);
        $clean = trim(preg_replace('/\s+/', '-', trim($clean)));

        return $clean !== '' ? $clean : null;
    }

    /** 用 conversation_id 找既有對話；找不到（如電話來電）則用 session 綁定，最後退回為第一個使用者開新對話。 */
    private function resolveConversation(?int $id, ?string $session): Conversation
    {
        if ($id && ($conv = Conversation::find($id))) {
            return $conv;
        }

        $userId = User::orderBy('id')->value('id') ?? 1;

        if ($session) {
            $existing = Conversation::where('voice_sid', $session)->latest('id')->first();
            if ($existing) {
                return $existing;
            }

            return Conversation::create([
                'user_id' => $userId,
                'voice_sid' => $session,
                'title' => '語音對話',
            ]);
        }

        return Conversation::create(['user_id' => $userId, 'title' => '語音對話']);
    }
}
