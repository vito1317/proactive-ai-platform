<?php

namespace App\Pai\Schedule;

use App\Pai\Cognition\LlmClient;
use App\Pai\Integrations\Calendar;
use App\Pai\Integrations\Mailer;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 每日個人 Podcast：把「今天行程＋天氣＋未讀信＋昨天 AI 幫你做了什麼」寫成
 * 雙主持人（安安×阿佩）的對話腳本，逐句用 edge TTS 合成（兩個音色），
 * 串接成一支 MP3 存 storage/podcast，推播連結＋手機自動開播放。
 * 開關：podcast.enabled（預設關）；時間：podcast.time（預設 07:50）。
 */
class PodcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    private const HOST_A = ['name' => '安安', 'speaker' => 'Vivian'];  // zh-TW 女聲

    private const HOST_B = ['name' => '阿佩', 'speaker' => 'Maple'];   // zh-TW 男聲

    public function __construct(private readonly int $uid, private readonly bool $autoPlay = true) {}

    public function handle(Settings $settings, Calendar $cal, Mailer $mail, LlmClient $llm, Notifier $notifier): void
    {
        \App\Pai\Agent\Tenant::set($this->uid);

        // 1) 素材：今日簡報（天氣/行程/未讀信）＋昨天 AI 活動
        $briefing = '';
        try {
            $briefing = BriefingJob::build($settings, $cal, $mail);
        } catch (Throwable) {
        }
        $yesterday = $this->yesterdayDigest();

        // 2) 雙主持人腳本
        try {
            $v = $llm->chatJson([
                ['role' => 'system', 'content' => '你是晨間 podcast 編劇。把素材寫成兩位主持人的輕鬆對話腳本：'
                    .self::HOST_A['name'].'（女，活潑開場與轉場）與 '.self::HOST_B['name'].'（男，補充細節、偶爾吐槽）。'
                    .'規則：台灣正體中文、口語化、每句 ≤60 字、共 8~14 句、輪流說話；'
                    .'內容涵蓋：問候與今天日期星期 → 天氣與穿著建議 → 今日行程重點 → 昨天 AI 幫使用者做的事（若有）→ 未讀信提醒（若有）→ 一句鼓勵收尾。'
                    .'不要捏造素材裡沒有的事。只輸出 JSON：{"lines":[{"host":"A|B","text":"..."}]}'],
                ['role' => 'user', 'content' => "【今日簡報素材】\n{$briefing}\n\n【昨天 AI 活動】\n{$yesterday}"],
            ], ['max_tokens' => 1200]);
        } catch (Throwable $e) {
            Log::warning('Podcast 腳本生成失敗', ['uid' => $this->uid, 'error' => $e->getMessage()]);

            return;
        }
        $lines = collect((array) ($v['lines'] ?? []))
            ->filter(fn ($l) => trim((string) ($l['text'] ?? '')) !== '')->values();
        if ($lines->count() < 3) {
            return;
        }

        // 3) 逐句合成（兩個音色）→ MP3 幀直接串接
        $ttsUrl = rtrim((string) ($settings->get('podcast.tts_url') ?: 'http://127.0.0.1:8880'), '/');
        $mp3 = '';
        foreach ($lines as $l) {
            $host = strtoupper((string) ($l['host'] ?? 'A')) === 'B' ? self::HOST_B : self::HOST_A;
            try {
                $r = Http::timeout(120)->post($ttsUrl.'/tts/generate', [
                    'text' => (string) $l['text'], 'speaker' => $host['speaker'], 'language' => 'Chinese',
                ]);
                if ($r->ok() && strlen($r->body()) > 100) {
                    $mp3 .= $r->body();
                }
            } catch (Throwable) {
            }
        }
        if (strlen($mp3) < 10000) {
            Log::warning('Podcast 合成音訊過短，放棄', ['uid' => $this->uid, 'bytes' => strlen($mp3)]);

            return;
        }

        // 4) 存檔＋推播＋手機自動開播放
        $dir = storage_path('app/public/podcast');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = "podcast/{$this->uid}-".now('Asia/Taipei')->format('Ymd').'.mp3';
        file_put_contents(storage_path('app/public/').$file, $mp3);
        $url = rtrim((string) config('app.url'), '/').'/storage/'.$file;

        $mins = max(1, (int) round(strlen($mp3) / 1024 / 60)); // 粗估：edge mp3 ~48kbps ≈ 6KB/s
        try {
            $notifier->send("🎙️ 今天的晨間 Podcast 好了（約 {$mins} 分鐘）：{$url}");
        } catch (Throwable) {
        }
        if ($this->autoPlay && ($node = ReverseBus::ownerPhoneNode($this->uid)) !== null) {
            try {
                ReverseBus::fire($node, 'open_url', ['url' => $url]); // 系統播放器直接開
            } catch (Throwable) {
            }
        }
    }

    /** 昨天 AI 幫你做了什麼（給腳本的素材）。 */
    private function yesterdayDigest(): string
    {
        $from = now('Asia/Taipei')->subDay()->startOfDay()->utc();
        $to = now('Asia/Taipei')->startOfDay()->utc();
        $parts = [];
        try {
            $n = \App\Pai\Automation\Automation::where('user_id', $this->uid)->whereBetween('last_run_at', [$from, $to])->count();
            if ($n > 0) {
                $parts[] = "觸發了 {$n} 條自動化";
            }
            $hit = \App\Pai\Watch\WatchTask::where('user_id', $this->uid)->where('status', 'hit')->whereBetween('updated_at', [$from, $to])->count();
            if ($hit > 0) {
                $parts[] = "守望/盯梢命中 {$hit} 次";
            }
            $exp = \Illuminate\Support\Facades\DB::table('expenses')->where('user_id', $this->uid)->whereBetween('spent_at', [$from, $to]);
            if (($c = $exp->count()) > 0) {
                $parts[] = "記了 {$c} 筆帳共 $".number_format((float) $exp->sum('amount'));
            }
            $calls = \Illuminate\Support\Facades\DB::table('outbound_calls')->where('user_id', $this->uid)->whereBetween('created_at', [$from, $to])->where('status', 'completed')->count();
            if ($calls > 0) {
                $parts[] = "代打了 {$calls} 通電話";
            }
        } catch (Throwable) {
        }

        return $parts === [] ? '（昨天沒有特別的 AI 活動）' : implode('、', $parts);
    }
}
