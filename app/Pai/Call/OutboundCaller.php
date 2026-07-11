<?php

namespace App\Pai\Call;

use App\Models\User;
use App\Pai\Cognition\LlmClient;
use App\Pai\Mcp\ReverseBus;
use App\Pai\Notify\Notifier;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI 外撥電話核心：Twilio 撥號 + TwiML 回合制對話（<Say> zh-TW 語音、<Gather> 語音辨識）+ 收尾通知。
 * 每回合：對方說的話 → LLM 依目標決定下一句 / 判斷是否談完 → 回 TwiML。
 * 不用 Media Streams（免 WebSocket server），latency 每回合約 1~3 秒，訂位/詢問類對話夠用。
 */
class OutboundCaller
{
    private const MAX_TURNS = 16;          // 回合上限：超過就禮貌收尾（防跳針燒錢）

    private const TIME_LIMIT_SEC = 420;    // 通話硬上限 7 分鐘

    public function __construct(
        private readonly Settings $settings,
        private readonly LlmClient $llm,
        private readonly Notifier $notifier,
    ) {}

    /** 憑證齊全才可外撥；回 null=OK，否則回缺什麼（給技能直接回覆使用者）。 */
    public function configError(?int $uid = null): ?string
    {
        foreach (['twilio.account_sid', 'twilio.auth_token', 'twilio.from'] as $k) {
            if (trim((string) $this->settings->get($k, '')) === '') {
                return "尚未設定 {$k}（設定頁 SMS/Twilio 區塊），沒辦法外撥。";
            }
        }

        return null;
    }

    /** 用 Twilio 撥出這通電話；接通後 Twilio 會回打 turn webhook 取第一句話。 */
    public function place(OutboundCall $call): bool
    {
        $sid = (string) $this->settings->get('twilio.account_sid', '');
        $token = (string) $this->settings->get('twilio.auth_token', '');
        $from = (string) $this->settings->get('twilio.from', '');
        try {
            $resp = Http::asForm()->withBasicAuth($sid, $token)->timeout(20)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Calls.json", [
                    'To' => $call->to_number,
                    'From' => $from,
                    'Url' => url("/webhooks/call/turn/{$call->token}"),
                    'Method' => 'POST',
                    'StatusCallback' => url("/webhooks/call/status/{$call->token}"),
                    'StatusCallbackMethod' => 'POST',
                    'Timeout' => 25,
                    'TimeLimit' => self::TIME_LIMIT_SEC,
                ]);
            if ($resp->failed()) {
                Log::warning('Twilio 外撥失敗', ['call' => $call->id, 'body' => $resp->body()]);
                $call->status = 'failed';
                $call->result = 'Twilio 撥號失敗：'.mb_substr((string) $resp->json('message'), 0, 200);
                $call->save();

                return false;
            }
            $call->twilio_sid = (string) $resp->json('sid');
            $call->status = 'in_progress';
            $call->save();

            return true;
        } catch (Throwable $e) {
            $call->status = 'failed';
            $call->result = 'Twilio 撥號失敗：'.$e->getMessage();
            $call->save();

            return false;
        }
    }

    /**
     * 一個對話回合：把對方剛說的話記進逐字稿 → LLM 決定下一句與是否收尾 → 回 TwiML XML。
     * $speech 為 null = 剛接通的第一回合（AI 先開口自我介紹＋說明來意）。
     */
    public function turn(OutboundCall $call, ?string $speech): string
    {
        if ($speech !== null) {
            $call->appendTranscript('callee', trim($speech) !== '' ? trim($speech) : '（沒有聽到聲音）');
        }
        $call->turns = (int) $call->turns + 1;

        if ($call->turns > self::MAX_TURNS) {
            $bye = '不好意思，先不耽誤您時間，我再請本人跟您聯絡，謝謝，再見。';
            $call->appendTranscript('ai', $bye);
            $call->save();

            return $this->xmlSayHangup($bye);
        }

        try {
            $v = $this->llm->chatJson($this->turnMessages($call), ['max_tokens' => 400]);
        } catch (Throwable $e) {
            Log::warning('外撥回合 LLM 失敗', ['call' => $call->id, 'error' => $e->getMessage()]);
            $bye = '不好意思，我這邊訊號有點問題，晚點再打給您，謝謝。';
            $call->appendTranscript('ai', $bye);
            $call->save();

            return $this->xmlSayHangup($bye);
        }

        $say = trim((string) ($v['say'] ?? '')) ?: '不好意思，請您再說一次。';
        $call->appendTranscript('ai', $say);

        if (! empty($v['done'])) {
            $call->status = 'completed';
            $call->result = trim((string) ($v['result'] ?? '')) ?: null;
            $call->save();

            return $this->xmlSayHangup($say);
        }
        $call->save();

        $turnUrl = url("/webhooks/call/turn/{$call->token}");
        $voice = $this->voice();
        $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?><Response>'
            .'<Gather input="speech" language="zh-TW" speechTimeout="auto" timeout="6" actionOnEmptyResult="true" '
            .'action="'.$esc($turnUrl).'" method="POST">'
            .'<Say language="zh-TW" voice="'.$esc($voice).'">'.$esc($say).'</Say>'
            .'</Gather>'
            .'<Redirect method="POST">'.$esc($turnUrl).'</Redirect>'
            .'</Response>';
    }

    /** 通話結束（Twilio status callback）：補收尾＋總結＋通知使用者（手機推播＋念出）。只通知一次。 */
    public function finalize(OutboundCall $call, string $callStatus): void
    {
        if (! Cache::add("call:notified:{$call->id}", 1, 3600)) {
            return;
        }
        \App\Pai\Agent\Tenant::set($call->user_id);

        // 沒接 / 忙線 / 失敗
        if (in_array($callStatus, ['no-answer', 'busy', 'failed', 'canceled'], true) && $call->status !== 'completed') {
            $call->status = str_replace('-', '_', $callStatus);
            $call->result = $call->result ?: match ($callStatus) {
                'no-answer' => '對方沒接電話',
                'busy' => '對方忙線中',
                default => '撥號失敗',
            };
            $call->save();
            $this->notify($call, "📞 打給 {$call->to_number} 沒成功：{$call->result}。要再試一次跟我說。");

            return;
        }

        // 對方中途掛斷（AI 還沒判定 done）→ 用逐字稿補一句總結
        if ($call->status !== 'completed') {
            $call->status = 'completed';
        }
        if ($call->result === null || $call->result === '') {
            $call->result = $this->summarize($call);
        }
        $call->save();
        $this->notify($call, "📞 電話打完了（{$call->to_number}）：{$call->result}");
    }

    /** @return list<array<string,mixed>> */
    private function turnMessages(OutboundCall $call): array
    {
        $userName = trim((string) (User::find($call->user_id)?->name ?? '')) ?: '使用者';
        $sys = "你正在「代表 {$userName} 打電話」，電話已接通，對方通常是店家/客服。\n"
            ."這通電話的目標：{$call->goal}\n"
            ."規則：\n"
            ."・第一句先自我介紹（例：您好，我是{$userName}的語音助理，想幫他訂位/詢問…），之後每回合只說一到兩句、口語、簡短，像真人講電話。\n"
            ."・只使用目標裡提供的資訊；對方問到你沒有的資訊，就說「這部分我再跟本人確認後回覆您」，不可捏造。\n"
            ."・對方說的話來自語音辨識，可能有錯字，用情境推斷原意。\n"
            ."・目標達成（例如訂位成功並確認了時間人數）或確定無法達成（客滿/打錯/對方拒絕）→ done=true，say 放道謝收尾語，result 用一句話寫結果（例：已訂成功 7/15 晚上7點 4位，用王先生名字）。\n"
            ."・對方兩次都沒聲音或明顯打錯號碼 → 禮貌收尾 done=true。\n"
            .'只輸出 JSON：{"say":"下一句要說的話","done":false,"result":"done 時的一句話結果"}';

        $messages = [['role' => 'system', 'content' => $sys]];
        foreach ((array) ($call->transcript ?? []) as $m) {
            $messages[] = [
                'role' => ($m['role'] ?? '') === 'ai' ? 'assistant' : 'user',
                'content' => (string) ($m['text'] ?? ''),
            ];
        }
        if (count($messages) === 1) {
            $messages[] = ['role' => 'user', 'content' => '（電話剛接通，請開口說第一句）'];
        }

        return $messages;
    }

    private function summarize(OutboundCall $call): string
    {
        $t = $call->transcriptText();
        if (trim($t) === '') {
            return '電話接通了但沒有對話內容。';
        }
        try {
            return trim($this->llm->chat([
                ['role' => 'system', 'content' => '以下是 AI 代打電話的逐字稿。用台灣正體中文「一句話」總結這通電話的結果（目標有沒有達成、關鍵資訊）。只輸出那一句話。'],
                ['role' => 'user', 'content' => "目標：{$call->goal}\n\n逐字稿：\n{$t}"],
            ], ['max_tokens' => 200])) ?: '通話結束（無法判斷結果，逐字稿見通知）。';
        } catch (Throwable) {
            return '通話結束（無法判斷結果，逐字稿見通知）。';
        }
    }

    private function notify(OutboundCall $call, string $message): void
    {
        try {
            $this->notifier->send($message."\n\n逐字稿：\n".$call->transcriptText(1500));
        } catch (Throwable) {
        }
        $node = ReverseBus::ownerPhoneNode($call->user_id);
        if ($node !== null) {
            try {
                ReverseBus::fire($node, 'phone_speak', ['text' => $message]);
            } catch (Throwable) {
            }
        }
    }

    private function xmlSayHangup(string $say): string
    {
        $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?><Response>'
            .'<Say language="zh-TW" voice="'.$esc($this->voice()).'">'.$esc($say).'</Say>'
            .'<Hangup/></Response>';
    }

    private function voice(): string
    {
        return trim((string) $this->settings->get('call.tts_voice', '')) ?: 'Google.zh-TW-Wavenet-A';
    }
}
