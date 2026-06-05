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

        // 重型多步任務（比價/研究/分析/規劃…）→ 在背景連續操作，語音先回快ack，完成後通知
        // （這類在本地思考模型上要數分鐘，同步等會逾時 504）
        if ($this->isHeavyTask($transcript)) {
            $event = \App\Pai\Perception\PaiEvent::create([
                'source' => 'voice', 'topic' => 'console.request',
                'payload' => ['message' => $transcript, 'conversation_id' => $conv->id],
                'status' => \App\Pai\Perception\EventStatus::Received,
            ]);
            \App\Pai\Cognition\RouteCommandJob::dispatch($event->id);
            $ack = '好的，這需要連續查資料、整理，我在背景幫你處理，完成後會通知你並出現在對話裡。';
            $conv->addMessage('assistant', $ack, ['source' => 'voice', 'category' => 'task', 'event_id' => $event->id]);

            return response()->json([
                'reply' => $ack, 'speech' => $ack,
                'steps' => ['🧠 背景連續操作中…'],
                'meta' => ['category' => 'task', 'background' => true, 'event_id' => $event->id],
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

        // 系統狀態查詢（磁碟/記憶體/CPU…）→ 在目標節點跑真實指令拿真資料（不繞 LLM、不幻覺）
        if ($sys = $this->sysQuery($t)) {
            [$target, $targetLabel] = $this->targetGateway($t);
            $out = trim($this->runExec($target, $sys['cmd']));
            $speech = $this->summarizeSys($sys['key'], $out, $targetLabel);

            return [
                'reply' => "【{$targetLabel}・{$sys['label']}】\n".($out !== '' ? $out : '（沒有輸出）'),
                'speech' => $speech,
                'meta' => ['category' => 'skill', 'skill' => 'sysinfo', 'direct' => true, 'target' => $target],
                'step' => "📊 {$sys['label']}@{$targetLabel}",
            ];
        }

        // 複雜需求（夾帶「開/關/搜尋」以外的其他動作）→ 交給 LLM agentic。
        // 注意：純連接詞（然後/並…）不算複雜——「打開瀏覽器然後搜尋新聞」仍走直達。
        if (preg_match('/(訂|购买|購買|寄|寫一|写一|發送|发送|傳給|传给|分析|總結|总结|翻譯|翻译|比較|比较|規劃|规划|整理|預訂|预订|提醒我|排程|安裝|安装|刪除|删除)/u', $t)) {
            return null;
        }
        $hasOpen = (bool) preg_match('/(打開|打开|開啟|开启|啟動|启动|使用|叫出|呼叫|幫.{0,2}開|帮.{0,2}开|開一下|开一下|\bopen\b|\blaunch\b|\bstart\b|\buse\b)/iu', $t);
        $hasClose = (bool) preg_match('/(關閉|關掉|關起來|关闭|关掉|結束|结束|退出|\bclose\b|\bquit\b)/iu', $t);
        // 搜尋（含簡體：STT 常輸出簡體）
        $hasSearch = (bool) preg_match('/(搜尋|搜寻|搜索|查一下|查詢|查询|尋找|寻找|找一下|google一下|估狗|\bsearch\b|\bfind\b)/iu', $t);
        $hasBrowser = (bool) preg_match('/(瀏覽器|浏览器|chrome|google|browser|safari|firefox)/iu', $t);
        // 「打開視窗/窗口/網頁 + 搜尋」也視為要開瀏覽器
        $isWindow = (bool) preg_match('/(視窗|窗口|網頁|网页|window|分頁|分页)/iu', $t);
        // 「(瀏覽器或視窗) + 搜尋」即使沒明講「打開」也視為要開瀏覽器搜尋
        if (! $hasOpen && $hasSearch && ($hasBrowser || $isWindow)) {
            $hasOpen = true;
        }
        if (! $hasOpen && ! $hasClose) {
            return null;
        }

        $key = $this->appKey($t);
        // 要搜尋/開視窗但沒指明程式 → 用瀏覽器
        if ($key === null && $hasOpen && ($hasSearch || $isWindow)) {
            $key = 'chrome';
        }
        if ($key === null) {
            return null;
        }
        $label = ['chrome' => '瀏覽器', 'firefox' => 'Firefox', 'terminal' => '終端機', 'calculator' => '計算機', 'files' => '檔案', 'settings' => '設定', 'editor' => '編輯器'][$key] ?? '程式';
        [$target, $targetLabel] = $this->targetGateway($t);

        if ($hasClose) {
            $res = $this->runGui($target, 'close', $key, null);
            $fail = $this->guiFailed($res);

            return [
                'reply' => "好，已在{$targetLabel}關閉「{$label}」（{$res}）",
                'speech' => $fail ? $this->guiFailSpeech($targetLabel) : "好的，已經幫你關閉{$label}了。",
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
        $fail = $this->guiFailed($res);
        $disp = $q !== '' ? "「{$label}」並搜尋「{$q}」" : "「{$label}」";
        $spk = $fail
            ? $this->guiFailSpeech($targetLabel)
            : ($q !== '' ? "好的，已經幫你打開{$label}並搜尋{$q}了。" : "好的，已經幫你打開{$label}了。");

        return [
            'reply' => "好，已在{$targetLabel}開啟{$disp}（{$res}）",
            'speech' => $spk,
            'meta' => ['category' => 'skill', 'skill' => 'gui', 'direct' => true, 'action' => 'open', 'target' => $target],
            'step' => "🚀 開啟：{$label}@{$targetLabel}",
        ];
    }

    /** 是否為「重型多步任務」（需連續上網/比較/研究，數分鐘）→ 改背景跑，避免同步逾時。 */
    private function isHeavyTask(string $t): bool
    {
        return (bool) preg_match('/(比價|比价|研究|調查|调查|分析|彙整|汇整|整理出|規劃|规划|行程|攻略|最便宜|最划算|哪間|哪家|住宿|機票|机票|飯店|饭店|報告|报告)/u', $t);
    }

    /**
     * 系統狀態查詢 → 真實指令。回 ['cmd','label','key'] 或 null。
     * 故意把「磁鐵/磁盤」等 STT 常見誤聽也對應到磁碟，繞過辨識誤差。
     */
    private function sysQuery(string $t): ?array
    {
        $n = mb_strtolower($t);
        $isQuery = (bool) preg_match('/(查|看|顯示|显示|檢查|检查|多少|剩|用量|狀態|状态|還有|还有|有多|空間|空间|容量|多滿|多满)/u', $t);
        // 磁碟（含誤聽：磁鐵/磁盤/硬盤）
        if (preg_match('/(磁碟|磁盤|磁盘|磁鐵|磁铁|硬碟|硬盘|disk|儲存空間|存储空间|容量|空間|空间)/u', $t)) {
            return ['cmd' => 'df -h', 'label' => '磁碟用量', 'key' => 'disk'];
        }
        // 記憶體（Mac 無 free，退而用 vm_stat / top）
        if (preg_match('/(記憶體|记忆体|內存|内存|memory|\bram\b)/iu', $t)) {
            return ['cmd' => 'free -h 2>/dev/null || (top -l 1 2>/dev/null | grep -i phys) || vm_stat', 'label' => '記憶體', 'key' => 'mem'];
        }
        // CPU / 負載 / 開機多久
        if ($isQuery && preg_match('/(cpu|處理器|处理器|負載|负载|\bload\b|開機多久|开机多久|運行時間|运行时间|uptime)/iu', $t)) {
            return ['cmd' => 'uptime', 'label' => 'CPU 負載 / 運行時間', 'key' => 'cpu'];
        }
        // 概括系統狀態
        if (preg_match('/(系統狀態|系统状态|系統資訊|系统信息|機器狀態|机器状态)/u', $t)) {
            return ['cmd' => 'uname -a; echo; uptime; echo; df -h | head -6', 'label' => '系統狀態', 'key' => 'sys'];
        }

        return null;
    }

    /** 在目標節點（local=主節點 gateway，或某 MCP gateway）跑唯讀指令，回 stdout。 */
    private function runExec(string $target, string $cmd): string
    {
        $name = ($target === '' || $target === 'local') ? 'gateway' : $target;
        $server = \App\Pai\Mcp\McpServer::where('name', $name)->where('enabled', true)->first();
        if (! $server) {
            return "（節點「{$name}」未連線）";
        }
        $r = app(\App\Pai\Mcp\McpClient::class)->callTool($server->url, $server->headers ?? [], 'exec', ['cmd' => $cmd]);

        return ($r['ok'] ?? false) ? (string) ($r['text'] ?? '') : '（執行失敗：'.($r['error'] ?? '未知').'）';
    }

    /** 把系統指令輸出整理成一句口語朗讀。 */
    private function summarizeSys(string $key, string $out, string $targetLabel): string
    {
        if (str_contains($out, '未連線') || str_contains($out, '執行失敗')) {
            return "抱歉，{$targetLabel} 目前沒連上線，查不到。";
        }
        if ($key === 'disk') {
            // 取根目錄（/）那行的使用百分比與可用空間
            foreach (preg_split('/\R/', $out) as $line) {
                if (preg_match('#(\d+)%\s+/$#', $line, $m) || (str_contains($line, ' /') && preg_match('/(\d+)%/', $line, $m))) {
                    $avail = preg_match('/([\d.]+\s*[KMGT]i?)\s+\d+%/', $line, $a) ? $a[1] : '';
                    return "{$targetLabel} 的磁碟使用了 {$m[1]}%".($avail ? "，還有 {$avail} 可用" : '')."。";
                }
            }

            return "已查到 {$targetLabel} 的磁碟用量，詳細列在畫面上。";
        }

        return "已查到 {$targetLabel} 的".($key === 'mem' ? '記憶體' : ($key === 'cpu' ? 'CPU 負載' : '系統'))."狀態，詳細列在畫面上。";
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
            'calculator' => 'calculator', '計算機' => 'calculator', '计算器' => 'calculator', '計算器' => 'calculator', '计算机' => 'calculator', '計算机' => 'calculator', '計算' => 'calculator', '计算' => 'calculator',
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
            $a = ['name' => $key];
            if ($arg) {
                $a['url'] = $arg;   // 開瀏覽器並導到搜尋網址
            }
            $r = $client->callTool($server->url, $server->headers ?? [], 'open_app', $a);
        }

        return ($r['ok'] ?? false) ? (string) ($r['text'] ?? '已執行') : ('遠端執行失敗：'.($r['error'] ?? '未知'));
    }

    /** runGui 結果是否代表失敗（節點離線/找不到/遠端錯誤）。 */
    private function guiFailed(string $res): bool
    {
        return (bool) preg_match('/(找不到節點|遠端執行失敗|找不到 open-app|失敗|error|refused|not found)/iu', $res);
    }

    /** 節點離線時的朗讀提示。 */
    private function guiFailSpeech(string $targetLabel): string
    {
        return "抱歉，{$targetLabel} 目前沒有連上線，沒辦法在那台開啟。請先把該節點的 gateway 連線起來。";
    }

    /** 從「搜尋 X / 查一下 X」抽出查詢字串。 */
    private function extractQuery(string $t): string
    {
        // 取搜尋動詞之後的全部當查詢（保留「的新聞」「資料」等，因為它們常是查詢的一部分）
        if (preg_match('/(?:搜尋|搜寻|搜索|查一下|查詢|查询|尋找|寻找|找一下|google一下|估狗|search|find)\s*(.+)$/iu', $t, $m)) {
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
