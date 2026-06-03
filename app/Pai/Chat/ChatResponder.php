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
    ) {}

    /**
     * @return array{reply: string, meta: array<string, mixed>}
     */
    public function respond(Conversation $conv, string $userMessage): array
    {
        $category = $this->category($conv, $userMessage);

        return $category === 'chat'
            ? ['reply' => trim($this->llm->chat($this->chatMessages($conv))), 'meta' => ['category' => 'chat']]
            : $this->act($category, $userMessage);
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
            .'用繁體中文、簡潔友善地回答。若使用者想做事，鼓勵他直接用白話描述，你會自動處理。';
        if ($conv->summary) {
            $content .= "\n\n[先前對話摘要（自動壓縮）]\n".$conv->summary;
        }
        $history = $conv->activeMessages()->get()->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all();

        return [['role' => 'system', 'content' => $content], ...array_slice($history, -8)];
    }

    /** 執行非閒聊類動作（任務 / 新增領域 / 設定通知）。 */
    public function act(string $category, string $userMessage): array
    {
        return match ($category) {
            'task' => $this->task($userMessage),
            'new_domain' => $this->newDomain($userMessage),
            'configure_notify' => $this->configureNotify($userMessage),
            default => ['reply' => '（無對應動作）', 'meta' => ['category' => $category]],
        };
    }

    private function task(string $message): array
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
            'payload' => ['message' => $message],
        ]);
        RunCoordinatorJob::dispatch($event->id, $event->domain);

        return [
            'reply' => "好的，我判斷這屬於「{$r['domain']}」領域，已交給協調者處理（事件 #{$event->id}）。"
                .'稍後可在中控台的「AI 認知運行」看到推理與處置；若有高風險動作會通知你核准。',
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
        $tested = false;
        if ($r['fields'] !== [] && ($this->notifier->configured()[$r['channel']] ?? false)) {
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
