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
        // 句中任一處出現「開啟動詞」即視為開程式意圖（不限句首，容許「你可以打開…嗎」這類問句）
        $hasOpenVerb = (bool) preg_match('/(打開|打开|開啟|开启|啟動|启动|幫.{0,2}開|帮.{0,2}开|開一下|开一下|\bopen\b|\blaunch\b|\bstart\b)/iu', $t);
        if ($hasOpenVerb) {
            // 直接掃整句裡的已知 app 關鍵字（chrome/瀏覽器/計算機…）
            $cmd = $this->resolveAppCommand($t);
            if ($cmd === null) {
                return null;
            }
            $skill = app(\App\Pai\Skills\SkillRegistry::class)->get('open-app');
            if (! $skill) {
                return null;
            }
            $result = $skill->run(['command' => $cmd]);
            $label = $this->appLabel($t);

            return [
                'reply' => "好，已在主節點幫你開啟「{$label}」🚀（{$result}）",  // 顯示含細節
                'speech' => "好的，已經幫你打開{$label}了。",                      // 朗讀乾淨口語
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
        // 口語名 → GUI 啟動器白名單 key（透過 pai-gui-open 在主節點圖形 session 真的開出視窗）
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
        foreach ($keys as $k => $key) {
            if (str_contains($n, $k)) {
                // 以 GUI 使用者身份在 Wayland session 啟動（sudoers 已允許 web 使用者）
                return 'sudo -u '.escapeshellarg($this->guiUser()).' /usr/local/bin/pai-gui-open '.escapeshellarg($key);
            }
        }

        return null; // 非已知 GUI app → 交回 agentic（可能是別的操作）
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
