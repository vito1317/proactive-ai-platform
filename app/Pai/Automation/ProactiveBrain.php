<?php

namespace App\Pai\Automation;

use App\Models\User;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Memory\UserMemory;
use App\Pai\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * 主動思考大腦：AI「無時無刻自己想」——排程定期觸發，讓 agent 根據時間＋使用者長期記憶＋
 * 已建立的自動化，自己判斷現在有沒有該主動做的事（提醒上班/行程、建立自動化、貼心提醒）。
 *
 * 用 agent 既有工具行動（create-automation / phone_notify / phone_speak / 實際幫忙），
 * 多數時候應「什麼都不做」(NOOP) 以免打擾。預設關閉，per-account 開啟。
 */
class ProactiveBrain
{
    public function __construct(private readonly Settings $settings) {}

    /** 排程每幾分鐘呼叫；對每個開啟主動思考的帳號，依其節奏觸發一次思考。 */
    public function tick(): void
    {
        $now = now('Asia/Taipei');
        foreach (User::all() as $user) {
            $uid = $user->id;
            if (! (bool) $this->settings->get('proactive.enabled', false, $uid)) {
                continue;
            }
            // 安靜時段（預設 22:00–07:00）不主動打擾
            $quiet = (string) ($this->settings->get('proactive.quiet', '22:00-07:00', $uid) ?: '');
            if ($this->inQuiet($now->format('H:i'), $quiet)) {
                continue;
            }
            $every = max(5, (int) ($this->settings->get('proactive.every_min', 30, $uid) ?: 30));
            if (! Cache::add("proactive:ran:{$uid}", 1, $every * 60)) {
                continue; // 還沒到下一次思考時間
            }
            try {
                $this->think($user);            // 即時提醒
                $this->designWorkflows($user);  // 自己設計新的自動化工作流（每天最多一次）
            } catch (\Throwable) {
            }
        }
    }

    /** 觸發一次主動思考：把情境餵給 agent，讓它自己決定要不要行動。 */
    public function think(User $user): void
    {
        $uid = $user->id;
        $now = now('Asia/Taipei');
        $mem = UserMemory::where('user_id', $uid)->orderByDesc('pinned')->orderByDesc('id')->limit(40)->get()
            ->map(fn ($m) => '・'.$m->content)->implode("\n") ?: '（沒有記憶）';
        $autos = Automation::where('user_id', $uid)->get()
            ->map(fn ($a) => "#{$a->id} {$a->name}".($a->enabled ? '' : '（已停用）'))->implode("\n") ?: '（還沒有自動化）';

        // 時段
        $h = (int) $now->format('H');
        $part = $h < 6 ? '凌晨' : ($h < 11 ? '早上' : ($h < 14 ? '中午' : ($h < 18 ? '下午' : ($h < 22 ? '晚上' : '深夜'))));

        // 今日行事曆（讀手機，best-effort）
        $cal = '（讀不到行事曆）';
        $node = $this->ownerPhoneNode($uid);
        if ($node !== null) {
            try {
                $r = \App\Pai\Mcp\ReverseBus::call($node, 'calendar_read', ['days' => 1], 20);
                $cal = trim((string) ($r['text'] ?? '')) ?: '（今天沒有行事曆事件）';
            } catch (\Throwable) {
            }
        }

        // 最近做過的主動行為（避免重複）
        $convPrev = Conversation::where('voice_sid', "proactive:{$uid}")->latest('id')->first();
        $recent = $convPrev ? $convPrev->messages()->where('role', 'assistant')->where('meta->acted', true)
            ->latest('id')->limit(5)->get()->map(fn ($m) => '・'.mb_substr($m->content, 0, 60))->implode("\n") : '';
        $recent = $recent ?: '（最近沒有主動行為）';

        $prompt = <<<TXT
（這是你的「主動思考」時刻：系統定期喚醒你，使用者現在沒在跟你說話。）
現在：{$now->format('Y-m-d H:i')}（星期{$now->isoWeekday()}，{$part}）。

任務：主動找出「此刻」一件對使用者真正有用、且這個時間點剛好合適的事去做。可參考的時機：
- 早上：給今日重點（行事曆有什麼、提醒帶東西/天氣）。
- 接下來幾小時有行程：提醒準備、或確認交通。
- 晚上：預告明天的行程。
- 發現使用者有重複性需求卻還沒有對應自動化 → 用 create-automation 幫他建一條。
- 任何貼心又不打擾的小提醒。

規則：
- **這次請務必挑一件「現在最有用」的事，實際用工具做出來**（phone_notify 發通知是最常用的；或 phone_speak 念出 / create-automation 建自動化），並用一句話說明你做了什麼。
- 例如：上面行事曆若有「今天稍後」或「明天」的行程 → 發一則 phone_notify 幫他預告＋提醒要帶的東西/交通；早上 → 發今日重點。
- **唯一可以回「NOOP」的情況：行事曆與記憶完全沒有任何可參考資訊。** 只要有任何行程或記憶，就不要 NOOP。
- 不要重複「最近已做過」清單裡完全一樣的事；通勤遲到、行程出發已有專門功能處理，不用你重做。

【現在時段】{$part}
【今日行事曆】
{$cal}

【使用者長期記憶】
{$mem}

【已建立的自動化】
{$autos}

【你最近已主動做過的事（別重複）】
{$recent}
TXT;

        try {
            $conv = Conversation::where('voice_sid', "proactive:{$uid}")->latest('id')->first()
                ?? Conversation::create(['voice_sid' => "proactive:{$uid}", 'user_id' => $uid, 'title' => '主動思考']);

            // 由 PHP 決定「該不該主動」（模型給 NOOP 選項就會偷懶）：有行事曆內容 + 距上次簡報夠久才發。
            $hasMaterial = str_contains($cal, '・') || $mem !== '（沒有記憶）';
            $throttled = ! \Illuminate\Support\Facades\Cache::add("proactive:briefed:{$uid}", 1, 4 * 3600); // 4 小時最多一則
            $acted = false;
            $out = '';
            if ($hasMaterial && ! $throttled) {
                // LLM 只負責把情境寫成一則貼心提醒（不給 NOOP 選項，避免偷懶）
                \App\Pai\Agent\Tenant::set($uid);
                $sys = '你是主動貼心的個人助理。把下面情境整理成「一則」要主動傳給使用者的提醒訊息：繁體中文、最多兩三句、可帶 emoji、聚焦今天/接下來最該知道的事與提醒。'
                    .'直接輸出那則訊息本身，不要給多個選項、不要任何解釋或前言。';
                // 只給乾淨情境（不含 NOOP 規則，否則模型會偷懶選 NOOP）
                $context = "現在：{$now->format('Y-m-d H:i')}（星期{$now->isoWeekday()}，{$part}）。\n\n"
                    ."【今日/明日行事曆】\n{$cal}\n\n【關於使用者的長期記憶】\n{$mem}\n\n"
                    ."【你最近已提醒過（不要重複）】\n{$recent}";
                $out = trim((string) app(\App\Pai\Cognition\LlmClient::class)->chat(
                    [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $context]],
                    ['max_tokens' => 220]
                ));
                // 只去掉「選項X：」「**標題**」這類前言/標題行；刪完若變空就保留原文（避免把訊息刪光）
                $clean = preg_replace('/^\s*#{1,6}\s.*$/mu', '', (string) $out);           // markdown 標題
                $clean = preg_replace('/^\s*\*{0,2}\s*(選項|方案|版本)\s*[一二三四五六\d].*$/mu', '', (string) $clean); // 「選項三：…」
                $clean = trim((string) $clean);
                $out = $clean !== '' ? $clean : trim((string) $out);
                $acted = $out !== '' && ! str_contains(mb_strtoupper($out), 'NOOP');
            }

            if ($acted) {
                // 真的發出去：通知 + 手機念出
                try {
                    app(\App\Pai\Notify\Notifier::class)->send('🧠 '.$out);
                    if ($node) {
                        \App\Pai\Mcp\ReverseBus::fire($node, 'phone_speak', ['text' => $out]);
                    }
                } catch (\Throwable) {
                }
            }
            $conv->addMessage('assistant', $acted ? $out : 'NOOP（這次沒事可做）', [
                'source' => 'proactive', 'acted' => $acted, 'at' => $now->format('Y-m-d H:i'),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * 自動化設計師：讓 AI 依使用者情境「自己設計新的工作流」並建成 Automation。每天最多一次、不重複。
     * 由 think() 呼叫。
     */
    public function designWorkflows(User $user): void
    {
        $uid = $user->id;
        if (! \Illuminate\Support\Facades\Cache::add("proactive:designed:{$uid}", 1, 20 * 3600)) {
            return; // 一天最多設計一次
        }
        $now = now('Asia/Taipei');
        $mem = UserMemory::where('user_id', $uid)->orderByDesc('pinned')->limit(40)->get()
            ->map(fn ($m) => '・'.$m->content)->implode("\n") ?: '（沒有記憶）';
        $existing = Automation::where('user_id', $uid)->get()->map(fn ($a) => '・'.$a->name)->implode("\n") ?: '（還沒有）';
        $node = $this->ownerPhoneNode($uid);
        $cal = '';
        if ($node) {
            try {
                $cal = trim((string) (\App\Pai\Mcp\ReverseBus::call($node, 'calendar_read', ['days' => 3], 20)['text'] ?? ''));
            } catch (\Throwable) {
            }
        }

        $sys = <<<'SYS'
你是自動化工作流設計師。根據使用者的長期記憶、近期行事曆與「已存在的自動化」，設計 0~2 條「全新、實際會幫到他」的自動化工作流。
嚴格只輸出 JSON 陣列（不要解釋、不要 markdown）。每個元素：
{"name":"流程名稱","spec":{
  "trigger":{"type":"daily|interval|unlock","at":"HH:MM","every_min":N,"window":["07:00","09:30"],"days":[1,2,3,4,5]},
  "conditions":[{"type":"location_outside|location_inside","place":"地址或公司","radius_m":400}|{"type":"weekday","days":[..]}|{"type":"time_after","time":"HH:MM"}|{"type":"always"}],
  "actions":[{"type":"notify","text":"…"}|{"type":"speak","text":"…"}|{"type":"open_map","place":"…"}|{"type":"ask","question":"…","yes":[…],"no":[]}]
}}
文字可用變數 {name}{km}{drive}{eta}{late}{time}{place}。不要和「已存在的自動化」重複。沒有值得新增的就輸出 []。
SYS;
        $ctx = "現在：{$now->format('Y-m-d H:i')}（星期{$now->isoWeekday()}）。\n\n【長期記憶】\n{$mem}\n\n【近期行事曆】\n".($cal ?: '（無）')."\n\n【已存在的自動化】\n{$existing}";

        try {
            \App\Pai\Agent\Tenant::set($uid);
            $raw = (string) app(\App\Pai\Cognition\LlmClient::class)->chat(
                [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $ctx]],
                ['max_tokens' => 800]
            );
            // 抽出 JSON 陣列
            if (preg_match('/\[.*\]/su', $raw, $mm)) {
                $raw = $mm[0];
            }
            $specs = json_decode($raw, true);
            if (! is_array($specs)) {
                return;
            }
            $existingNames = Automation::where('user_id', $uid)->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
            $created = [];
            foreach (array_slice($specs, 0, 2) as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $spec = $row['spec'] ?? null;
                if ($name === '' || ! is_array($spec) || empty($spec['trigger']) || empty($spec['actions'])) {
                    continue;
                }
                if (in_array(mb_strtolower($name), $existingNames, true)) {
                    continue; // 不重複
                }
                Automation::create(['user_id' => $uid, 'name' => $name, 'enabled' => true, 'spec' => $spec, 'state' => [], 'source' => 'ai']);
                $created[] = $name;
            }
            if (! empty($created)) {
                $msg = '🤖 我幫你建立了自動化流程：'.implode('、', $created).'。可到「自動化」頁查看或停用。';
                try {
                    app(\App\Pai\Notify\Notifier::class)->send($msg);
                } catch (\Throwable) {
                }
                $conv = Conversation::where('voice_sid', "proactive:{$uid}")->latest('id')->first()
                    ?? Conversation::create(['voice_sid' => "proactive:{$uid}", 'user_id' => $uid, 'title' => '主動思考']);
                $conv->addMessage('assistant', $msg, ['source' => 'proactive', 'acted' => true, 'at' => $now->format('Y-m-d H:i')]);
            }
        } catch (\Throwable) {
        }
    }

    private function ownerPhoneNode(int $uid): ?string
    {
        try {
            $owned = \App\Pai\Mcp\McpServer::where('user_id', $uid)->where('url', 'like', 'reverse://%')->pluck('name')->all();
            $online = array_values(array_filter(\App\Pai\Mcp\ReverseBus::onlineNodes(), fn ($n) => in_array($n, $owned, true)));
            $phones = array_values(array_filter($online, fn ($n) => ! preg_match('/mac|macbook|imac|air|pc|desktop|windows|linux|laptop/i', $n)));

            return $phones[0] ?? $online[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function inQuiet(string $hm, string $range): bool
    {
        if (! preg_match('/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', trim($range), $m)) {
            return false;
        }
        [$a, $b] = [$m[1], $m[2]];

        return $a <= $b ? ($hm >= $a && $hm < $b) : ($hm >= $a || $hm < $b); // 跨午夜
    }
}
