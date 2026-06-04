<?php

namespace App\Pai\Chat;

use App\Pai\Cognition\IntentClassifier;
use App\Pai\Cognition\LlmClient;
use App\Pai\Cognition\MetaRouter;
use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Domains\DomainPackGenerator;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Notify\Notifier;
use App\Pai\Notify\NotifyAssistant;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use App\Pai\Perception\Severity;
use App\Pai\Settings\Settings;
use App\Pai\Skills\SkillRunner;

/**
 * 對話式「指揮 AI」的回應引擎：帶多輪上下文，先用 MetaRouter 判斷意圖，
 * 閒聊就對話回覆，要做事就觸發任務 / 新增領域 / 設定通知，並回一段自然語言。
 */
class ChatResponder
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly MetaRouter $meta,
        private readonly IntentClassifier $classifier,
        private readonly DomainPackGenerator $generator,
        private readonly NotifyAssistant $assistant,
        private readonly Notifier $notifier,
        private readonly Settings $settings,
        private readonly SkillRunner $skills,
    ) {}

    /**
     * 決定如何回應。閒聊回 ['stream' => true, 'messages' => [...]]（供串流逐字輸出）；
     * 其餘（待確認技能 / 技能 / 任務 / 新增領域 / 通知）直接回最終結果。
     * 集中於此，讓串流端 (SSE / TG / LINE) 與非串流端共用同一套路由。
     *
     * @return array{stream: bool, messages?: list<array>, reply?: string, meta?: array<string,mixed>}
     */
    public function route(Conversation $conv, string $userMessage, ?callable $onStep = null): array
    {
        $step = $onStep ?? fn (string $t) => null;

        // 1) 待確認的高風險技能——這則可能是「確認/取消」
        if ($resolved = $this->skills->resolvePending($conv, $userMessage)) {
            return ['stream' => false, ...$resolved];
        }

        // 2) 自訂斜線指令 /name → 展開成內容後照常處理（聊天室/TG/LINE 共用）
        if ($expanded = app(SlashCommands::class)->expand($userMessage)) {
            $step('⚡ [COMMAND_EXPAND] 正在執行自訂指令...');
            $userMessage = $expanded;
        }

        $step('💠 [INTENT_DECODING] 正在解碼指令意圖...');
        $category = $this->category($conv, $userMessage);
        if ($category === 'chat') {
            return ['stream' => true, 'messages' => $this->chatMessages($conv)];
        }
        if ($category === 'skill') {
            $r = $this->skills->handle($conv, $userMessage, $onStep);
            // 沒對應到技能 → 退回正常對話回答
            if (! empty($r['meta']['no_skill'])) {
                return ['stream' => true, 'messages' => $this->chatMessages($conv)];
            }

            return ['stream' => false, ...$r];
        }
        $step(match ($category) {
            'task' => '📂 [COORDINATOR_HANDOFF] 正在委派領域協調者...',
            'new_domain' => '🧩 [COMPILE_DOMAIN] 正在編譯新領域組件...',
            'configure_notify' => '🔔 [SYNC_NOTIFY] 正在同步通知通訊協定...',
            default => '⚙️ [SYSTEM_EXECUTING] 正在處理系統指令...',
        });

        return ['stream' => false, ...$this->act($category, $userMessage, $conv)];
    }

    /**
     * @return array{reply: string, meta: array<string, mixed>}
     */
    public function respond(Conversation $conv, string $userMessage): array
    {
        $r = $this->route($conv, $userMessage);

        return $r['stream']
            ? ['reply' => trim($this->llm->chat($r['messages'])), 'meta' => ['category' => 'chat']]
            : ['reply' => $r['reply'], 'meta' => $r['meta']];
    }

    /** 技能執行器（供串流端處理待確認/技能類訊息）。 */
    public function skills(): SkillRunner
    {
        return $this->skills;
    }

    /**
     * 多模態：看圖回答（vision）。把對話脈絡 + 圖片送給多模態 LLM。
     * $dataUri 形如 data:image/jpeg;base64,...
     */
    public function visionReply(Conversation $conv, string $caption, string $dataUri): string
    {
        $messages = $this->chatMessages($conv); // system + 摘要 + 近期歷史
        $messages[] = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $caption !== '' ? $caption : '請看這張圖片，描述內容並回答我可能想知道的重點。'],
            ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
        ]];

        return trim($this->llm->chat($messages));
    }

    /** 判斷意圖類別（帶最近對話脈絡）。 */
    public function category(Conversation $conv, string $userMessage): string
    {
        $history = $conv->activeMessages()->get()->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all();

        return $this->meta->classify($this->contextString($history))['category'];
    }

    /** 閒聊回覆要送給 LLM 的訊息（system + 壓縮摘要 + 近 8 則）——供串流用。 */
    public function chatMessages(Conversation $conv): array
    {
        $content = '你是 PAI 主動式 AI 平台的助理。平台能：監聽事件與日誌、資安事件響應、'
            .'開發自動化（讀 repo、跑測試、提修補）、新增監控領域、設定通知（Telegram/LINE）。'
            .'用繁體中文、簡潔友善地回答。若使用者想做事，鼓勵他直接用白話描述，你會自動處理。'
            ."\n\n【重要】這一則是純對話回合，**絕對不要模擬、假裝或編造**讀檔、查 log、跑指令、docker exec 等查詢過程或結果。"
            .'**更不要說「好，我來執行」「我來檢查一下」「我正在執行…請稍候」這種你其實不會在這一則動手做的承諾**——你這一則只能用「已知資訊」誠實對話，沒有實際執行工具的能力。'
            .'平台確實有能力實際執行（讀檔、查 log、跑指令、docker exec、看 nginx 設定…），但那會由「技能代理」在另一條路徑處理；'
            .'若使用者是要實際操作或查真實資料，系統已會自動把那種訊息導去實際執行——所以你只要正常回答即可，不要替它空講要做什麼。'
            .'其餘一般問題就依下方已提供的資訊（領域包摘要、對話脈絡）誠實作答。';

        // 注入目前已載入的領域包摘要，讓 AI 能回答「有哪些領域 / 各做什麼」
        $packs = array_values(app(DomainRegistry::class)->all());
        if ($packs !== []) {
            $lines = array_map(fn ($p) => "・{$p->domain}（{$p->autonomy}）：{$p->description}", $packs);
            $content .= "\n\n[目前已載入的領域包]\n".implode("\n", $lines)
                ."\n（使用者問起時可據此說明；要看某領域的觸發條件/工具/劇本細節，可用 describe-domain 技能或到「領域包」頁。）";
        }
        if ($conv->summary) {
            $content .= "\n\n[先前對話摘要（自動壓縮）]\n".$conv->summary;
        }
        $history = $conv->activeMessages()->get()->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all();

        return [['role' => 'system', 'content' => $content], ...array_slice($history, -8)];
    }

    /** 執行非閒聊類動作（任務 / 新增領域 / 設定通知）。 */
    public function act(string $category, string $userMessage, ?Conversation $conv = null): array
    {
        return match ($category) {
            'task' => $this->task($userMessage, $conv),
            'new_domain' => $this->newDomain($userMessage),
            'configure_notify' => $this->configureNotify($userMessage),
            default => ['reply' => '（無對應動作）', 'meta' => ['category' => $category]],
        };
    }

    private function task(string $message, ?Conversation $conv = null): array
    {
        $r = $this->classifier->classify($message);
        if ($r['domain'] === null) {
            return ['reply' => '我不太確定該交給哪個領域處理，能再具體一點嗎？（目前領域：'
                .implode('、', $this->domainKeys()).'）', 'meta' => ['category' => 'task']];
        }

        $event = PaiEvent::create([
            'source' => 'chat', 'topic' => $r['topic'], 'domain' => $r['domain'],
            'intent' => 'user-request', 'severity' => Severity::from($r['severity']),
            'status' => EventStatus::Routed, 'note' => '[對話任務] '.$r['rationale'],
            // 記下來源對話 → 任務完成後把結果回貼到這個對話（見 RunCoordinatorJob）
            'payload' => ['message' => $message, 'conversation_id' => $conv?->id],
        ]);
        RunCoordinatorJob::dispatch($event->id, $event->domain);

        return [
            'reply' => "好的，我判斷這屬於「{$r['domain']}」領域，已交給協調者處理（事件 #{$event->id}）。"
                .'處理完成後我會把結果回覆到這個對話（也會發通知）；若有高風險動作會請你核准。',
            'meta' => ['category' => 'task', 'event_id' => $event->id, 'domain' => $r['domain']],
        ];
    }

    private function newDomain(string $message): array
    {
        $res = $this->generator->generate($message);
        if (! $res['valid']) {
            return ['reply' => '我試著生成領域包，但資訊還不太夠：'.($res['errors'][0] ?? '請再描述清楚一點')
                .'。可以多說明觸發條件、要做的動作、哪些動作需要人工核准嗎？', 'meta' => ['category' => 'new_domain']];
        }
        $domain = $res['manifest']['domain'];
        file_put_contents(base_path("packs/{$domain}.yaml"), $res['yaml']);

        return [
            'reply' => "已依你的描述建立並啟用新領域「{$domain}」🧩。它現在會用平台的推理、記憶、風險閘與通知能力運作；到「領域包」頁可檢視細節。",
            'meta' => ['category' => 'new_domain', 'domain' => $domain],
        ];
    }

    private function configureNotify(string $message): array
    {
        $r = $this->assistant->extract($message);
        foreach ($r['fields'] as $key => $value) {
            $this->settings->set($key, $value);
        }
        $configured = $this->notifier->configured();
        $channel = $r['channel'];

        // 沒有提供新憑證，但該通道（或任一通道）已設定好 → 確定性正面回覆，
        // 不再依賴 LLM、避免誤要 token（使用者最常見的卡關點）。
        if ($r['fields'] === []) {
            $ready = array_keys(array_filter($configured));
            if (($channel !== 'unknown' && ($configured[$channel] ?? false)) || $ready !== []) {
                $names = ['telegram' => 'Telegram', 'line' => 'LINE', 'webhook' => 'Webhook'];
                $label = isset($names[$channel]) && ($configured[$channel] ?? false)
                    ? $names[$channel]
                    : implode('、', array_map(fn ($c) => $names[$c] ?? $c, $ready));

                return [
                    'reply' => "✅ {$label} 通知已設定完成，之後平台的通知（事件處置、待核准、動作完成等）都會送到這裡，不需要再提供 token。若要改推播對象或新增其他通道再告訴我即可。",
                    'meta' => ['category' => 'configure_notify', 'already_configured' => true],
                ];
            }
        }

        $tested = false;
        if ($r['fields'] !== [] && ($configured[$channel] ?? false)) {
            $tested = ! empty(array_filter($this->notifier->send('✅ PAI 通知測試：設定成功。')));
        }

        return [
            'reply' => $r['reply'].($tested ? '　我已發送一則測試訊息，請查收 📨。' : ''),
            'meta' => ['category' => 'configure_notify'],
        ];
    }

    /** @param  list<array{role:string,content:string}>  $history */
    private function contextString(array $history): string
    {
        $recent = array_slice($history, -4);

        return implode("\n", array_map(fn ($m) => "{$m['role']}: {$m['content']}", $recent));
    }

    private function domainKeys(): array
    {
        return array_keys(app(DomainRegistry::class)->all());
    }
}
