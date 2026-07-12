<?php

namespace App\Pai\Watch;

use App\Pai\Agent\Tenant;
use App\Pai\Cognition\LlmClient;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Notify\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 視覺守望的一輪 tick：截手機畫面 → 視覺 LLM 判斷是否命中守望目標 →
 * 命中：通知＋手機念出並結束；未命中：記下畫面狀態，delay interval_sec 排下一輪。
 * 用 tick_token 保證同一守望同時只有一條 Job 鏈（看門狗補發/佇列重啟不會疊鏈）。
 */
class WatchTickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(
        private readonly int $watchId,
        private readonly string $token,
    ) {}

    public function handle(LlmClient $llm, Notifier $notifier): void
    {
        $w = WatchTask::find($this->watchId);
        if (! $w || $w->status !== 'active' || $w->tick_token !== $this->token) {
            return; // 已結束 / 權杖已換發（這條是舊鏈）→ 安靜退出
        }
        Tenant::set($w->user_id);

        if ($w->isExpired()) {
            $this->finish($w, 'expired', $notifier,
                "⏱️ 守望結束：時間到了，沒有等到「{$w->goal}」（共看了 {$w->run_count} 次）。需要就再叫我盯。");

            return;
        }

        // ── 網頁來源（web:{url}）：抓頁面文字給 LLM 判斷（價格/有貨/開賣盯梢）──────
        if (str_starts_with((string) $w->source, 'web:')) {
            $this->tickWeb($w, $llm, $notifier);

            return;
        }

        $img = '';
        if (str_starts_with((string) $w->source, 'live:')) {
            // 即時投影/鏡頭來源：直接吃手機/網頁持續推送的當前畫面（不用叫手機截圖）
            $sid = substr((string) $w->source, 5);
            $img = (string) \Illuminate\Support\Facades\Cache::get('vision:pending:'.$sid, '');
            if ($img === '') {
                $this->softFail($w, $notifier, '即時畫面已停止推送（投影/鏡頭關掉了）');

                return;
            }
        } else {
            // 節點掉線 → 換一台在線手機續盯
            $node = ($w->node !== null && ReverseBus::lastSeen($w->node) !== null)
                ? $w->node
                : WatchTask::phoneNode($w->user_id);
            if ($node !== null && $node !== $w->node) {
                $w->node = $node;
            }
            if ($node !== null) {
                $shot = ReverseBus::call($node, 'screen_shot', [], 60);
                $img = ! empty($shot['ok']) ? $this->extractImage((string) ($shot['text'] ?? '')) : '';
            }
            if ($img === '') {
                $this->softFail($w, $notifier, '截不到手機畫面（手機可能離線或螢幕鎖定）');

                return;
            }
        }

        // 畫面跟上一輪完全相同 → 不必問 LLM，直接等下一輪
        $hash = md5($img);
        if ($hash === $w->last_hash) {
            $this->markRun($w, null, $hash);
            $this->reschedule($w);

            return;
        }

        try {
            $v = $llm->chatJson([
                ['role' => 'system', 'content' => '你是「螢幕守望員」：使用者要你持續盯著手機畫面，直到指定狀況發生。'
                    .'只看這張當前截圖，輸出一個 JSON 物件：'
                    .'{"hit":true|false,"desc":"一句話描述目前畫面狀態（給下一輪比對）","report":"hit 時一句話說明發生了什麼"}。'
                    .'判斷要嚴格：畫面「明確」符合使用者等的狀況才 hit=true；載入中、廣告、彈窗、看不確定一律 hit=false。'
                    .'desc/report 用台灣正體中文。只輸出 JSON。'],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => "守望目標：{$w->goal}\n上一輪畫面狀態：".($w->last_desc ?: '（第一輪，還沒有紀錄）')],
                    ['type' => 'image_url', 'image_url' => ['url' => $img]],
                ]],
            ], ['max_tokens' => 300]);
        } catch (Throwable $e) {
            Log::warning('視覺守望判讀失敗', ['watch' => $w->id, 'error' => $e->getMessage()]);
            $this->softFail($w, $notifier, '看圖判讀一直失敗');

            return;
        }

        $w->fail_count = 0;
        if (! empty($v['hit'])) {
            $report = trim((string) ($v['report'] ?? '')) ?: '你要我盯的狀況出現了';
            $w->result = $report;
            $this->finish($w, 'hit', $notifier, "🎯 守望「{$w->goal}」有結果：{$report}", speak: true);

            return;
        }

        $this->markRun($w, trim((string) ($v['desc'] ?? '')) ?: null, $hash);
        $this->reschedule($w);
    }

    /** 網頁盯梢的一輪：抓頁面 → 文字 LLM 判斷（帶上一輪狀態供比較「降價/變化」）。 */
    private function tickWeb(WatchTask $w, LlmClient $llm, Notifier $notifier): void
    {
        $url = substr((string) $w->source, 4);
        $text = $this->fetchPageText($url);
        if ($text === '') {
            $this->softFail($w, $notifier, "抓不到網頁內容（{$url}）");

            return;
        }
        $hash = md5($text);
        if ($hash === $w->last_hash) { // 頁面完全沒變 → 不必問 LLM
            $this->markRun($w, null, $hash);
            $this->reschedule($w);

            return;
        }
        try {
            $v = $llm->chatJson([
                ['role' => 'system', 'content' => '你是「網頁守望員」：使用者要你盯著一個網頁，直到指定狀況發生（降價到門檻、有貨、開賣、出現某資訊）。'
                    .'根據這輪抓到的頁面文字判斷，輸出 JSON：'
                    .'{"hit":true|false,"desc":"一句話記下目前關鍵狀態（如：價格 NT$590、缺貨中）","report":"hit 時一句話說明發生了什麼（含關鍵數字）"}。'
                    .'判斷要嚴格：條件「明確」成立才 hit=true；頁面載入不完整、看不到關鍵資訊一律 hit=false。台灣正體中文。只輸出 JSON。'],
                ['role' => 'user', 'content' => "守望目標：{$w->goal}\n上一輪狀態：".($w->last_desc ?: '（第一輪）')."\n\n頁面文字：\n{$text}"],
            ], ['max_tokens' => 300]);
        } catch (Throwable $e) {
            Log::warning('網頁守望判讀失敗', ['watch' => $w->id, 'error' => $e->getMessage()]);
            $this->softFail($w, $notifier, '頁面判讀一直失敗');

            return;
        }
        $w->fail_count = 0;
        if (! empty($v['hit'])) {
            $report = trim((string) ($v['report'] ?? '')) ?: '你等的狀況出現了';
            $w->result = $report;
            $this->finish($w, 'hit', $notifier, "🎯 盯到了「{$w->goal}」：{$report}\n{$url}", speak: true);

            return;
        }
        $this->markRun($w, trim((string) ($v['desc'] ?? '')) ?: null, $hash);
        $this->reschedule($w);
    }

    /** 抓網頁可讀文字（去 script/style/tag、壓空白、截 6000 字）。 */
    private function fetchPageText(string $url): string
    {
        try {
            $html = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Mobile Safari/537.36',
                'Accept-Language' => 'zh-TW,zh;q=0.9',
            ])->timeout(25)->get($url)->body();
        } catch (Throwable) {
            return '';
        }
        $html = (string) preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[ \t]{2,}|\r/', ' ', (string) preg_replace('/\n{2,}/', "\n", $text)));

        return mb_substr($text, 0, 6000);
    }

    /** 從節點回傳文字抽出截圖 data URI（[[IMG]]data:image/... 或整段就是 data URI）。 */
    private function extractImage(string $text): string
    {
        if (preg_match('/\[\[IMG\]\](data:image\/[a-z]+;base64,[A-Za-z0-9+\/=]+)/', $text, $m)) {
            return $m[1];
        }
        $t = trim($text);

        return str_starts_with($t, 'data:image/') ? $t : '';
    }

    private function markRun(WatchTask $w, ?string $desc, string $hash): void
    {
        if ($desc !== null) {
            $w->last_desc = $desc;
        }
        $w->last_hash = $hash;
        $w->run_count = (int) $w->run_count + 1;
        $w->last_run_at = now();
        $w->save();
    }

    /** 這一輪失敗（截圖/判讀）：連 3 次 → error 收尾；否則照常排下一輪再試。 */
    private function softFail(WatchTask $w, Notifier $notifier, string $why): void
    {
        $w->fail_count = (int) $w->fail_count + 1;
        if ($w->fail_count >= 3) {
            $this->finish($w, 'error', $notifier, "⚠️ 守望「{$w->goal}」中斷：{$why}，連三次失敗先停了。手機恢復後再叫我盯。");

            return;
        }
        $w->last_run_at = now();
        $w->save();
        $this->reschedule($w);
    }

    private function reschedule(WatchTask $w): void
    {
        // 即時來源畫面是推上來的（不必叫手機截圖），允許更密的節奏；網頁盯梢最密 5 分鐘（別轟炸網站）
        $min = match (true) {
            str_starts_with((string) $w->source, 'live:') => 5,
            str_starts_with((string) $w->source, 'web:') => 300,
            default => 10,
        };
        self::dispatch($w->id, $this->token)->delay(now()->addSeconds(max($min, (int) $w->interval_sec)));
    }

    /** 收尾：更新狀態、推通知；命中時再讓手機直接念出來；AI 自己開的鏡頭順手關掉。 */
    private function finish(WatchTask $w, string $status, Notifier $notifier, string $message, bool $speak = false): void
    {
        $w->status = $status;
        $w->save();
        if (\Illuminate\Support\Facades\Cache::pull("watch:autocam:{$w->id}")) {
            $node = $w->node ?? WatchTask::phoneNode($w->user_id);
            if ($node !== null) {
                try {
                    ReverseBus::fire($node, 'camera_vision', ['on' => false]);
                } catch (Throwable) {
                }
            }
        }
        try {
            $notifier->send($message);
        } catch (Throwable) {
        }
        if ($speak && $w->node !== null) {
            try {
                ReverseBus::fire($w->node, 'phone_speak', ['text' => $message]);
            } catch (Throwable) {
            }
        }
    }
}
