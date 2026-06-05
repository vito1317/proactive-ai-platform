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

            return response()->json([
                'reply' => $direct['reply'],          // 顯示用（含技術細節）
                'speech' => $direct['speech'] ?? $direct['reply'], // 朗讀用（乾淨口語）
                'steps' => [$direct['step'] ?? '⚡ 直接執行'],
                'meta' => $direct['meta'],
                'conversation_id' => $conv->id,
            ]);
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
            'speech' => $this->speechClean($reply), // 朗讀用：去掉指令/路徑/網址/emoji，避免 TTS 念出怪聲
            'steps' => $steps,
            'meta' => $meta,
            'conversation_id' => $conv->id,
        ]);
    }

    /** 把回覆清成「適合朗讀」的乾淨口語：去除程式碼/路徑/網址/emoji/markdown，並精簡長度。 */
    private function speechClean(string $text): string
    {
        $t = $text;
        $t = preg_replace('/```.*?```/su', '', $t);                 // 程式碼區塊
        $t = preg_replace('/`[^`]*`/u', '', $t);                    // 行內 code
        $t = preg_replace('#https?://\S+#u', '網址', $t);            // 網址
        $t = preg_replace('#(?:sudo |/)[\w./@\-]+#u', '', $t);       // 指令/絕對路徑
        $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $t); // emoji
        // 去 markdown：標題#、粗體**、項目符號、引用、表格線
        $t = preg_replace('/^[ \t]*[#>\-*•・|]+[ \t]*/mu', '', $t);
        $t = str_replace(['**', '*', '＿', '`', '|'], '', $t);
        $t = preg_replace('/[（(][^）)]*detached[^）)]*[）)]/iu', '', $t);
        $t = preg_replace('/[ \t]*\R+[ \t]*/u', '，', $t);          // 換行→停頓
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = preg_replace('/，{2,}/u', '，', $t);
        $t = trim($t, " 。.·、,，\t\n");

        // 過長 → 只念前幾句，其餘請看畫面（避免長文 TTS 變怪、太久）
        if (mb_strlen($t) > 160) {
            $parts = preg_split('/(?<=[。！？!?])/u', $t, -1, PREG_SPLIT_NO_EMPTY);
            $acc = '';
            foreach ($parts as $p) {
                if (mb_strlen($acc.$p) > 150) {
                    break;
                }
                $acc .= $p;
            }
            $t = trim($acc) !== '' ? trim($acc).' 詳細內容已顯示在畫面上。' : mb_substr($t, 0, 150).'…';
        }

        return $t !== '' ? $t : $text;
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
        $hasOpen = (bool) preg_match('/(打開|打开|開啟|开启|啟動|启动|幫.{0,2}開|帮.{0,2}开|開一下|开一下|\bopen\b|\blaunch\b|\bstart\b)/iu', $t);
        $hasClose = (bool) preg_match('/(關閉|關掉|關起來|关闭|关掉|結束|结束|退出|\bclose\b|\bquit\b)/iu', $t);
        $hasSearch = (bool) preg_match('/(搜尋|搜索|查一下|查詢|查询|google一下|估狗|\bsearch\b|\bfind\b)/iu', $t);
        if (! $hasOpen && ! $hasClose) {
            return null;
        }

        $key = $this->appKey($t);
        // 要搜尋但沒指明程式 → 用瀏覽器
        if ($key === null && $hasSearch && $hasOpen) {
            $key = 'chrome';
        }
        if ($key === null) {
            return null;
        }
        $label = $this->appLabel($t);
        [$target, $targetLabel] = $this->targetGateway($t);

        if ($hasClose) {
            $res = $this->runGui($target, 'close', $key, null);

            return [
                'reply' => "好，已在{$targetLabel}關閉「{$label}」（{$res}）",
                'speech' => "好的，已經幫你關閉{$label}了。",
                'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'close', 'target' => $target],
                'step' => "🛑 關閉：{$label}@{$targetLabel}",
            ];
        }

        // 開啟（可帶搜尋）
        $arg = null;
        $q = '';
        if ($hasSearch && $key === 'chrome') {
            $q = $this->extractQuery($t);
            if ($q !== '') {
                $arg = 'https://www.google.com/search?q='.rawurlencode($q);
            }
        }
        $res = $this->runGui($target, 'open', $key, $arg);
        $disp = $q !== '' ? "「{$label}」並搜尋「{$q}」" : "「{$label}」";
        $spk = $q !== '' ? "好的，已經幫你打開{$label}並搜尋{$q}了。" : "好的，已經幫你打開{$label}了。";

        return [
            'reply' => "好，已在{$targetLabel}開啟{$disp}（{$res}）",
            'speech' => $spk,
            'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'open', 'target' => $target],
            'step' => "🚀 開啟：{$label}@{$targetLabel}",
        ];
    }

    /** 口語句子 → GUI 白名單 key（chrome/firefox/terminal/calculator/files/settings/editor）或 null。 */
    private function appKey(string $name): ?string
    {
        $n = mb_strtolower(trim($name));
        $keys = [
            'chrome' => 'chrome', 'google chrome' => 'chrome', 'google' => 'chrome', 'googlechrome' => 'chrome',
            '谷歌' => 'chrome', '瀏覽器' => 'chrome', '浏览器' => 'chrome', 'chromium' => 'chrome', 'safari' => 'chrome', 'edge' => 'chrome',
            'firefox' => 'firefox', '火狐' => 'firefox',
            'terminal' => 'terminal', '終端' => 'terminal', '終端機' => 'terminal', '终端' => 'terminal',
            'calculator' => 'calculator', '計算機' => 'calculator', '计算器' => 'calculator', '計算器' => 'calculator',
            'files' => 'files', '檔案' => 'files', '文件管理' => 'files', '檔案總管' => 'files', 'nautilus' => 'files',
            'settings' => 'settings', '設定' => 'settings', '设置' => 'settings', '控制台' => 'settings',
            'gedit' => 'editor', '記事本' => 'editor', '文字編輯' => 'editor', '編輯器' => 'editor',
        ];
        foreach ($keys as $k => $v) {
            if (str_contains($n, $k)) {
                return $v;
            }
        }

        return null;
    }

    /** 決定在哪個節點操作：句中指名 > 預設設定 > local。回傳 [target, 顯示名]。 */
    private function targetGateway(string $t): array
    {
        $low = mb_strtolower($t);
        if (preg_match('/(主節點|主节点|伺服器|服务器|這台|这台|本機|本机|server)/u', $t)) {
            return ['local', '主節點'];
        }
        // 句中提到某個已註冊 MCP 節點名稱 → 用它
        foreach (\App\Pai\Mcp\McpServer::where('enabled', true)->get() as $s) {
            if ($s->name !== 'gateway' && str_contains($low, mb_strtolower($s->name))) {
                return [$s->name, $s->name];
            }
        }
        if (preg_match('/(我的mac|我的電腦|我的电脑|我的筆電|mac\b|macbook)/iu', $t)) {
            $mac = \App\Pai\Mcp\McpServer::where('enabled', true)->where('name', 'like', '%mac%')->first();
            if ($mac) {
                return [$mac->name, $mac->name];
            }
        }
        $def = (string) $this->settings->get('voice.default_gateway', config('pai.voice.default_gateway', 'local'));

        return $def === '' || $def === 'local' ? ['local', '主節點'] : [$def, $def];
    }

    /** 在指定節點開/關 GUI app。local→pai-gui-open；遠端→該 MCP gateway 的 open_app/exec。 */
    private function runGui(string $target, string $action, string $key, ?string $arg): string
    {
        if ($target === 'local') {
            $base = 'sudo -u '.escapeshellarg($this->guiUser()).' /usr/local/bin/pai-gui-open ';
            $cmd = $action === 'close'
                ? $base.'--close '.escapeshellarg($key)
                : $base.escapeshellarg($key).($arg ? ' '.escapeshellarg($arg) : '');
            $skill = app(\App\Pai\Skills\SkillRegistry::class)->get('open-app');

            return $skill ? $skill->run(['command' => $cmd]) : '找不到 open-app 技能';
        }
        // 遠端節點：走該 gateway 的 MCP
        $server = \App\Pai\Mcp\McpServer::where('name', $target)->where('enabled', true)->first();
        if (! $server) {
            return "找不到節點「{$target}」";
        }
        $client = app(\App\Pai\Mcp\McpClient::class);
        if ($action === 'close') {
            $procs = ['chrome' => 'chrom', 'firefox' => 'firefox', 'terminal' => 'erminal', 'calculator' => 'alculator', 'files' => 'inder', 'settings' => 'ettings', 'editor' => 'edit'];
            $r = $client->callTool($server->url, $server->headers ?? [], 'exec', ['cmd' => 'pkill -if '.escapeshellarg($procs[$key] ?? $key)]);
        } else {
            $name = $arg ? $key.' '.$arg : $key; // 遠端 open_app 接受 name；URL 一併帶（gateway 端可用）
            $r = $client->callTool($server->url, $server->headers ?? [], 'open_app', ['name' => $arg ? $arg : $key]);
        }

        return ($r['ok'] ?? false) ? (string) ($r['text'] ?? '已執行') : ('遠端執行失敗：'.($r['error'] ?? '未知'));
    }

    /** 從「搜尋 X / 查一下 X」抽出查詢字串。 */
    private function extractQuery(string $t): string
    {
        // 取搜尋動詞之後的全部當查詢（保留「的新聞」「資料」等，因為它們常是查詢的一部分）
        if (preg_match('/(?:搜尋|搜索|查一下|查詢|查询|google一下|估狗|search|find)\s*(.+)$/iu', $t, $m)) {
            $q = trim($m[1]);
            $q = preg_replace('/^(一下|並|并|和|然後|然后|再|去|的)\s*/u', '', $q);
            $q = rtrim($q, " 。.!！?？、,，");

            return trim($q);
        }

        return '';
    }

    /** 由口語句子推出友善的 app 名稱（給朗讀用）。 */
    private function appLabel(string $transcript): string
    {
        $n = mb_strtolower($transcript);
        $labels = [
            'chrome' => 'Chrome', '谷歌' => 'Chrome', '瀏覽器' => '瀏覽器', '浏览器' => '瀏覽器', 'chromium' => 'Chrome',
            'safari' => 'Chrome', 'edge' => 'Chrome', 'firefox' => 'Firefox', '火狐' => 'Firefox',
            '終端' => '終端機', '终端' => '終端機', 'terminal' => '終端機',
            '計算機' => '計算機', '计算器' => '計算機', 'calculator' => '計算機',
            '檔案' => '檔案', 'files' => '檔案', '設定' => '設定', 'settings' => '設定',
            '記事本' => '記事本', '編輯器' => '編輯器',
        ];
        foreach ($labels as $k => $v) {
            if (str_contains($n, $k)) {
                return $v;
            }
        }

        return '程式';
    }

    /** 主節點圖形 session 的使用者（可由 config 覆寫）。 */
    private function guiUser(): string
    {
        return (string) (config('pai.voice.gui_user') ?: 'intellitrust');
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
