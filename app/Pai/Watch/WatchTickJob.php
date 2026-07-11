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

        // 節點掉線 → 換一台在線手機續盯
        $node = ($w->node !== null && ReverseBus::lastSeen($w->node) !== null)
            ? $w->node
            : WatchTask::phoneNode($w->user_id);
        if ($node !== null && $node !== $w->node) {
            $w->node = $node;
        }

        $img = '';
        if ($node !== null) {
            $shot = ReverseBus::call($node, 'screen_shot', [], 60);
            $img = ! empty($shot['ok']) ? $this->extractImage((string) ($shot['text'] ?? '')) : '';
        }
        if ($img === '') {
            $this->softFail($w, $notifier, '截不到手機畫面（手機可能離線或螢幕鎖定）');

            return;
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
        self::dispatch($w->id, $this->token)->delay(now()->addSeconds(max(10, (int) $w->interval_sec)));
    }

    /** 收尾：更新狀態、推通知；命中時再讓手機直接念出來。 */
    private function finish(WatchTask $w, string $status, Notifier $notifier, string $message, bool $speak = false): void
    {
        $w->status = $status;
        $w->save();
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
