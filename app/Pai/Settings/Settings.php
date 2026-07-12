<?php

namespace App\Pai\Settings;

use Throwable;

/**
 * 設定存取層：DB 覆寫（pai_settings）疊在 config/pai.php 預設之上。
 *
 * get() 每次直接讀 DB，所以後台改動會即時生效（不必重啟 queue worker）。
 * 表不存在時優雅退回 config 預設。
 */
class Settings
{
    /**
     * LLM API 供應商 preset：選了供應商就自動帶 base_url（OpenAI 相容端點），
     * 只要再填該供應商的 api_key + model 即可。選 custom 則完全用下面的 llm.base_url。
     * （base_url 若有填，一律覆寫 preset —— 方便接自架/代理。）
     */
    public const PROVIDERS = [
        'custom' => ['label' => '自訂 / 自架（用下方端點）', 'base_url' => '', 'hint' => '自行填 llm.base_url'],
        'local' => ['label' => '本機 llama-server', 'base_url' => 'http://127.0.0.1:10003/v1', 'hint' => '本地模型'],
        'ollama' => ['label' => 'Ollama（本機）', 'base_url' => 'http://127.0.0.1:11434/v1', 'hint' => 'llama3.1 / qwen2.5 …'],
        'openai' => ['label' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1', 'hint' => 'gpt-4o / gpt-4o-mini / o3 …'],
        'anthropic' => ['label' => 'Anthropic Claude', 'base_url' => 'https://api.anthropic.com/v1', 'hint' => 'claude-opus-4 / claude-sonnet-4 …'],
        'gemini' => ['label' => 'Google Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'hint' => 'gemini-2.0-flash / gemini-1.5-pro'],
        'openrouter' => ['label' => 'OpenRouter（聚合）', 'base_url' => 'https://openrouter.ai/api/v1', 'hint' => '任一上游模型，如 anthropic/claude-3.5-sonnet'],
        'groq' => ['label' => 'Groq（極速）', 'base_url' => 'https://api.groq.com/openai/v1', 'hint' => 'llama-3.3-70b / mixtral …'],
        'deepseek' => ['label' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1', 'hint' => 'deepseek-chat / deepseek-reasoner'],
        'mistral' => ['label' => 'Mistral', 'base_url' => 'https://api.mistral.ai/v1', 'hint' => 'mistral-large-latest …'],
        'xai' => ['label' => 'xAI Grok', 'base_url' => 'https://api.x.ai/v1', 'hint' => 'grok-2-latest …'],
        'together' => ['label' => 'Together AI', 'base_url' => 'https://api.together.xyz/v1', 'hint' => 'meta-llama/… / Qwen/…'],
        'fireworks' => ['label' => 'Fireworks AI', 'base_url' => 'https://api.fireworks.ai/inference/v1', 'hint' => 'accounts/fireworks/models/…'],
        'perplexity' => ['label' => 'Perplexity', 'base_url' => 'https://api.perplexity.ai', 'hint' => 'sonar / sonar-pro（內建上網）'],
        'cerebras' => ['label' => 'Cerebras（極速）', 'base_url' => 'https://api.cerebras.ai/v1', 'hint' => 'llama-3.3-70b …'],
    ];

    /** 後台可編輯的欄位定義（型別 / 預設來自 config）。 */
    public const FIELDS = [
        'llm.provider' => ['label' => 'LLM 供應商', 'type' => 'select', 'group' => 'LLM'],
        'llm.base_url' => ['label' => 'LLM 端點 (OpenAI 相容；填了會覆寫供應商 preset)', 'type' => 'string', 'group' => 'LLM'],
        'llm.api_key' => ['label' => 'AI API Token / 金鑰', 'type' => 'secret', 'group' => 'LLM'],
        'llm.model' => ['label' => '模型名稱', 'type' => 'string', 'group' => 'LLM'],
        'llm.temperature' => ['label' => 'Temperature', 'type' => 'number', 'group' => 'LLM', 'min' => 0, 'max' => 2, 'step' => 0.1],
        'llm.max_tokens' => ['label' => 'Max tokens（思考型模型需較大）', 'type' => 'int', 'group' => 'LLM', 'min' => 256, 'max' => 16384],
        'llm.context_window' => ['label' => 'Context window（模型上下文長度，prompt 預算依此裁切）', 'type' => 'int', 'group' => 'LLM', 'min' => 2048, 'max' => 1048576],
        'llm.small_model' => ['label' => '輕量模型（意圖分類/壓縮/萃取用；空=用主模型）', 'type' => 'string', 'group' => 'LLM'],
        'llm.small_base_url' => ['label' => '輕量模型端點（空=同主模型端點）', 'type' => 'string', 'group' => 'LLM'],
        'llm.small_api_key' => ['label' => '輕量模型金鑰（空=同主模型金鑰）', 'type' => 'secret', 'group' => 'LLM'],
        'llm.no_think' => ['label' => 'Prompt 帶 /no_think（Qwen 系抑制思考鏈；模型不支援就關）', 'type' => 'bool', 'group' => 'LLM'],
        'llm.timeout' => ['label' => '逾時 (秒)', 'type' => 'int', 'group' => 'LLM', 'min' => 10, 'max' => 600],
        'react.max_steps' => ['label' => 'ReAct 最大步數', 'type' => 'int', 'group' => 'ReAct', 'min' => 1, 'max' => 12],
        'react.reflect' => ['label' => '啟用自我反思', 'type' => 'bool', 'group' => 'ReAct'],
        'skills.allow_system_writes' => ['label' => '允許 AI 直接執行高風險自我修改技能（關閉時需對話確認）', 'type' => 'bool', 'group' => '技能'],
        'voice.stt_url' => ['label' => '語音轉文字 (STT) 端點', 'type' => 'string', 'group' => '語音'],
        'voice.fullduplex_enabled' => ['label' => '啟用全雙工語音（即時對話＋操控系統）', 'type' => 'bool', 'group' => '語音'],
        'voice.fullduplex_url' => ['label' => '全雙工語音 Socket.IO 來源（空=同源，經 nginx 反代）', 'type' => 'string', 'group' => '語音'],
        'voice.fullduplex_path' => ['label' => '全雙工語音 Socket.IO path', 'type' => 'string', 'group' => '語音'],
        'voice.agent_secret' => ['label' => '語音橋接共用密鑰（voice_server → 平台）', 'type' => 'secret', 'group' => '語音'],
        'voice.system_prompt' => ['label' => '語音助理人格 (system prompt)', 'type' => 'string', 'group' => '語音'],
        'voice.tts_engine' => ['label' => '語音念回引擎', 'type' => 'select', 'group' => '語音', 'options' => [
            ['value' => 'edge', 'label' => 'Edge-TTS（線上，最自然）'],
            ['value' => 'f5', 'label' => 'F5-TTS（離線，可克隆）'],
            ['value' => 'minicpm', 'label' => 'MiniCPM 原生（最低延遲）'],
        ]],
        'voice.tts_speaker' => ['label' => '語音音色（Edge 引擎時可選）', 'type' => 'select', 'group' => '語音', 'options' => [
            ['value' => 'Vivian', 'label' => 'Vivian — 台灣女聲'],
            ['value' => 'Maple', 'label' => 'Maple — 台灣男聲'],
            ['value' => 'Luna', 'label' => 'Luna — 中國女聲'],
            ['value' => 'Leo', 'label' => 'Leo — 中國男聲'],
            ['value' => 'Kai', 'label' => 'Kai — 中國男聲(渾厚)'],
            ['value' => 'Mia', 'label' => 'Mia — 英文女聲'],
            ['value' => 'Aria', 'label' => 'Aria — 英文女聲'],
            ['value' => 'Ryan', 'label' => 'Ryan — 英文男聲'],
        ]],
        'voice.default_gateway' => ['label' => '預設操作節點（開/關程式預設在哪台）', 'type' => 'select', 'group' => '語音'],
        'notify.webhook_url' => ['label' => 'Webhook URL (Slack/Discord)', 'type' => 'string', 'group' => '通知'],
        'notify.telegram.token' => ['label' => 'Telegram Bot Token', 'type' => 'secret', 'group' => '通知'],
        'notify.telegram.chat_id' => ['label' => 'Telegram Chat ID', 'type' => 'string', 'group' => '通知'],
        'notify.line.secret' => ['label' => 'LINE Channel Secret（雙向接收驗證用）', 'type' => 'secret', 'group' => '通知'],
        'notify.line.token' => ['label' => 'LINE Channel Access Token', 'type' => 'secret', 'group' => '通知'],
        'notify.line.to' => ['label' => 'LINE 推播對象 (userId/groupId)', 'type' => 'string', 'group' => '通知'],
        // 行事曆 / Gmail / 晨間簡報
        'calendar.ics_url' => ['label' => 'Google 行事曆「私人 iCal 網址」(secret address in iCal format)', 'type' => 'string', 'group' => '行事曆/郵件'],
        'mail.address' => ['label' => 'Gmail 信箱', 'type' => 'string', 'group' => '行事曆/郵件'],
        'mail.app_password' => ['label' => 'Gmail 應用程式密碼（Google 帳號→安全性→應用程式密碼）', 'type' => 'secret', 'group' => '行事曆/郵件'],
        'inbox.assistant_enabled' => ['label' => '啟用收件匣助理（新信自動分類：重要立刻通知+擬回覆草稿、一般每小時摘要、廣告靜音）', 'type' => 'bool', 'group' => '行事曆/郵件'],
        'briefing.enabled' => ['label' => '啟用每日晨間簡報', 'type' => 'bool', 'group' => '行事曆/郵件'],
        // 安全守護（手機傳感器哨兵：撞擊/跌倒偵測 → 確認 → 沒回應自動求援）
        'safety.enabled' => ['label' => '啟用安全守護（撞擊/跌倒自動確認求援）', 'type' => 'bool', 'group' => '安全守護'],
        'safety.no_response_sec' => ['label' => '沒回應幾秒後自動求援（預設 60）', 'type' => 'string', 'group' => '安全守護'],
        'safety.emergency_instruction' => ['label' => '求援動作（自然語言，交給 AI 執行，例：用LINE傳訊息給媽媽）', 'type' => 'string', 'group' => '安全守護'],
        'safety.hr_high' => ['label' => '心率偏高警戒（bpm，靜止時平均超過就提醒，預設 110）', 'type' => 'string', 'group' => '安全守護'],
        'safety.hr_low' => ['label' => '心率偏低警戒（bpm，平均低於就提醒，預設 40）', 'type' => 'string', 'group' => '安全守護'],
        // 通知分流（手機 App 通知 → urgent 立刻吵 / normal 每小時摘要 / noise 靜音）
        'notify_triage.muted_apps' => ['label' => '通知分流：直接靜音的 App（逗號分隔，如 蝦皮,Instagram）', 'type' => 'string', 'group' => '通知'],
        'briefing.time' => ['label' => '晨間簡報時間 (HH:MM)', 'type' => 'string', 'group' => '行事曆/郵件'],
        'briefing.place' => ['label' => '簡報天氣地點（預設台北）', 'type' => 'string', 'group' => '行事曆/郵件'],
        'reminder.lead_min' => ['label' => '行事曆事件提前提醒分鐘數', 'type' => 'int', 'group' => '行事曆/郵件', 'min' => 1, 'max' => 120],
        // 圖片生成（OpenAI 相容 /images/generations 端點，如 OpenAI / 本地 / 相容服務）
        'image.api_url' => ['label' => '生圖端點 (OpenAI 相容 /images/generations)', 'type' => 'string', 'group' => '生圖'],
        'image.api_key' => ['label' => '生圖 API 金鑰', 'type' => 'secret', 'group' => '生圖'],
        'image.model' => ['label' => '生圖模型名稱', 'type' => 'string', 'group' => '生圖'],
        // Discord 接入（Interactions /ask 斜線指令；填入 Discord 開發者後台的 Application ID + Public Key）
        'discord.app_id' => ['label' => 'Discord Application ID', 'type' => 'string', 'group' => 'Discord'],
        'discord.public_key' => ['label' => 'Discord Public Key（驗證互動簽章）', 'type' => 'string', 'group' => 'Discord'],
        // Slack 接入（Events API：@提及 bot 或私訊；填 Signing Secret + Bot Token）
        'slack.signing_secret' => ['label' => 'Slack Signing Secret（驗證請求簽章）', 'type' => 'secret', 'group' => 'Slack'],
        'slack.bot_token' => ['label' => 'Slack Bot Token (xoxb-…，回覆用)', 'type' => 'secret', 'group' => 'Slack'],
        // 第三方供應商
        'firecrawl.api_key' => ['label' => 'Firecrawl API Key（高品質網頁抓取/爬取）', 'type' => 'secret', 'group' => '供應商'],
        'fal.api_key' => ['label' => 'FAL API Key（FLUX 生圖/影片等模型）', 'type' => 'secret', 'group' => '供應商'],
        // Feishu / Lark（Events API：@提及或私訊 bot）
        'feishu.app_id' => ['label' => 'Feishu App ID', 'type' => 'string', 'group' => 'Feishu'],
        'feishu.app_secret' => ['label' => 'Feishu App Secret', 'type' => 'secret', 'group' => 'Feishu'],
        'feishu.verification_token' => ['label' => 'Feishu Verification Token', 'type' => 'secret', 'group' => 'Feishu'],
        // DingTalk（機器人 outgoing webhook；用 sessionWebhook 回覆）
        'dingtalk.app_secret' => ['label' => 'DingTalk 機器人 Signing Secret（驗證簽章）', 'type' => 'secret', 'group' => 'DingTalk'],
        // Mattermost（outgoing webhook 進、bot token 回）
        'mattermost.token' => ['label' => 'Mattermost Outgoing Webhook Token（驗證）', 'type' => 'secret', 'group' => 'Mattermost'],
        'mattermost.base_url' => ['label' => 'Mattermost 站台 URL（如 https://mm.example.com）', 'type' => 'string', 'group' => 'Mattermost'],
        'mattermost.bot_token' => ['label' => 'Mattermost Bot Token（回覆用）', 'type' => 'secret', 'group' => 'Mattermost'],
        // SMS（Twilio）
        'twilio.account_sid' => ['label' => 'Twilio Account SID', 'type' => 'string', 'group' => 'SMS/Twilio'],
        'twilio.auth_token' => ['label' => 'Twilio Auth Token', 'type' => 'secret', 'group' => 'SMS/Twilio'],
        'twilio.from' => ['label' => 'Twilio 發送號碼 (+1…)', 'type' => 'string', 'group' => 'SMS/Twilio'],
        'call.tts_voice' => ['label' => 'AI 外撥語音音色（Twilio <Say> voice，預設 Google.zh-TW-Wavenet-A）', 'type' => 'string', 'group' => 'SMS/Twilio'],
        // QQ（OneBot v11 / go-cqhttp / NapCat：事件 POST 進來，HTTP API 回覆）
        'onebot.secret' => ['label' => 'OneBot/QQ 簽章 Secret（HMAC 驗證，可空）', 'type' => 'secret', 'group' => 'QQ/OneBot'],
        'onebot.api_url' => ['label' => 'OneBot HTTP API URL（如 http://127.0.0.1:5700，回覆用）', 'type' => 'string', 'group' => 'QQ/OneBot'],
        'onebot.api_token' => ['label' => 'OneBot API access_token（可空）', 'type' => 'secret', 'group' => 'QQ/OneBot'],
        // BlueBubbles（iMessage 橋接 server）
        'bluebubbles.server_url' => ['label' => 'BlueBubbles Server URL', 'type' => 'string', 'group' => 'BlueBubbles'],
        'bluebubbles.password' => ['label' => 'BlueBubbles Server Password', 'type' => 'secret', 'group' => 'BlueBubbles'],
        // Signal（signal-cli-rest-api；輪詢收訊，HTTP 發訊）
        'signal.api_url' => ['label' => 'signal-cli-rest-api URL（如 http://127.0.0.1:8080）', 'type' => 'string', 'group' => 'Signal'],
        'signal.number' => ['label' => 'Signal 已註冊號碼 (+886…)', 'type' => 'string', 'group' => 'Signal'],
        // 早晨通勤遲到提醒（每帳號自己的公司/主管）
        'commute.enabled' => ['label' => '啟用早晨通勤遲到提醒', 'type' => 'bool', 'group' => '通勤提醒'],
        'commute.work_place' => ['label' => '公司地點（地址或「緯度,經度」；留空則用長期記憶裡的公司地址）', 'type' => 'string', 'group' => '通勤提醒'],
        'commute.work_start' => ['label' => '上班時間 (HH:MM；留空則用長期記憶，如「我九點上班」)', 'type' => 'string', 'group' => '通勤提醒'],
        'commute.work_days' => ['label' => '上班日 (如 1,2,3,4,5；1=一…7=日；留空=週一~週五或長期記憶)', 'type' => 'string', 'group' => '通勤提醒'],
        'commute.nav_app' => ['label' => '導航 App', 'type' => 'select', 'group' => '通勤提醒', 'options' => [
            ['value' => '', 'label' => '每次讓我選'],
            ['value' => 'google', 'label' => 'Google 地圖'],
            ['value' => '導航王', 'label' => '導航王'],
            ['value' => 'waze', 'label' => 'Waze'],
            ['value' => 'papago', 'label' => 'PaPaGO!'],
            ['value' => 'here', 'label' => 'HERE WeGo'],
            ['value' => 'osmand', 'label' => 'OsmAnd'],
        ]],
        'commute.lead_min' => ['label' => '提前監看分鐘數（在此區間內，到「上班時間−車程」就提醒該出發）', 'type' => 'int', 'group' => '通勤提醒', 'min' => 10, 'max' => 180],
        'commute.radius_m' => ['label' => '公司範圍半徑（公尺，超出才算還沒到）', 'type' => 'int', 'group' => '通勤提醒', 'min' => 50, 'max' => 5000],
        'commute.manager_via' => ['label' => '通知主管的管道', 'type' => 'select', 'group' => '通勤提醒', 'options' => [
            ['value' => 'line', 'label' => 'LINE（用你的 LINE bot 推給主管 userId）'],
            ['value' => 'telegram', 'label' => 'Telegram（推給主管 chat_id）'],
            ['value' => 'sms', 'label' => 'SMS（Twilio 簡訊到主管號碼）'],
        ]],
        'commute.manager_to' => ['label' => '主管聯絡 ID（LINE userId / TG chat_id / 手機號碼）', 'type' => 'string', 'group' => '通勤提醒'],
        'commute.manager_name' => ['label' => '主管稱呼（如「王經理」；留空=「主管」或長期記憶）', 'type' => 'string', 'group' => '通勤提醒'],
        'notify.default_platform' => ['label' => '預設發送平台（傳訊息給人時用）', 'type' => 'select', 'group' => '通勤提醒', 'options' => [
            ['value' => 'agent', 'label' => '交給 AI 決定（操作手機 LINE 等）'],
            ['value' => 'line', 'label' => 'LINE'],
            ['value' => 'telegram', 'label' => 'Telegram'],
            ['value' => 'sms', 'label' => 'SMS（Twilio）'],
        ]],
        'contacts.map' => ['label' => '名稱對應簿（每行「名稱=平台:目標」，平台 line/telegram/sms）', 'type' => 'string', 'group' => '通勤提醒'],
        'commute.message_template' => ['label' => '遲到訊息範本（可用 {manager} 主管稱呼、{late} 分鐘、{eta} 到達時間）', 'type' => 'string', 'group' => '通勤提醒'],
        // 通勤地理服務端點（免金鑰，可改自架）
        'commute.geocode_url' => ['label' => '地理編碼端點 (Nominatim)', 'type' => 'string', 'group' => '通勤提醒'],
        'commute.osrm_url' => ['label' => '車程估算端點 (OSRM)', 'type' => 'string', 'group' => '通勤提醒'],
        // 行程出發提醒（讀手機行事曆有地址的事件）
        'event_guard.enabled' => ['label' => '啟用行程出發提醒（行事曆有地點的事件，到該出發時提醒）', 'type' => 'bool', 'group' => '主動思考'],
        'event_guard.lead_min' => ['label' => '行程提前監看分鐘數（多久前開始算車程）', 'type' => 'int', 'group' => '主動思考', 'min' => 30, 'max' => 360],
        // 主動思考：AI 自己定期判斷要不要主動做事
        'proactive.enabled' => ['label' => '啟用 AI 主動思考（自己想要不要提醒/建自動化）', 'type' => 'bool', 'group' => '主動思考'],
        'proactive.every_min' => ['label' => '思考頻率（分鐘，越小越頻繁）', 'type' => 'int', 'group' => '主動思考', 'min' => 5, 'max' => 240],
        'proactive.quiet' => ['label' => '安靜時段（如 22:00-07:00，此區間不主動打擾）', 'type' => 'string', 'group' => '主動思考'],
    ];

    /**
     * 讀設定。$userId 不為 null 時：先找該帳號專屬設定（key 前綴 u{id}:），
     * 沒有再回全域設定，再回 config 預設 —— 這就是「所有設定都能分權」的核心。
     */
    public function get(string $key, mixed $default = null, ?int $userId = null): mixed
    {
        try {
            if ($userId !== null) {
                $u = PaiSetting::find("u{$userId}:{$key}");
                if ($u !== null) {
                    return $u->value;
                }
            }
            $row = PaiSetting::find($key);
        } catch (Throwable) {
            $row = null; // 表尚未建立 → 用 config
        }

        if ($row !== null) {
            return $row->value;
        }

        return config("pai.{$key}", $default);
    }

    /** 寫設定。$userId 不為 null → 寫該帳號專屬（不動全域）。 */
    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $k = $userId !== null ? "u{$userId}:{$key}" : $key;
        PaiSetting::updateOrCreate(['key' => $k], ['value' => $value]);
    }

    /**
     * 解析「實際要打的 LLM 端點」：明確填的 llm.base_url 優先，否則用供應商 preset 的 base_url。
     * LlmClient 用這個取代直接讀 llm.base_url，這樣選了供應商不填 base_url 也能用。
     */
    public function llmBaseUrl(?int $userId = null): string
    {
        $explicit = trim((string) $this->get('llm.base_url', '', $userId));
        if ($explicit !== '') {
            return $explicit;
        }
        $provider = (string) $this->get('llm.provider', 'custom', $userId);

        return (string) (self::PROVIDERS[$provider]['base_url'] ?? '');
    }

    /** 設定分類：把零散的 group 收進大分類，設定頁用分頁/分區呈現（依此順序）。 */
    public const CATEGORIES = [
        '🧠 核心 AI' => ['LLM', 'ReAct', '技能'],
        '🎙️ 語音助理' => ['語音'],
        '📅 行事曆 / 郵件' => ['行事曆/郵件', '通勤提醒'],
        '🤖 自動化 / 主動' => ['主動思考'],
        '🔔 通知' => ['通知', '通知（此帳號專屬）'],
        '💬 通訊管道' => ['Discord', 'Slack', 'Feishu', 'DingTalk', 'Mattermost', 'SMS/Twilio', 'QQ/OneBot', 'BlueBubbles', 'Signal'],
        '🔌 供應商 / 工具' => ['供應商', '生圖'],
    ];

    /** 某個 group 屬於哪個大分類（找不到 → 「其他」）。 */
    public static function categoryFor(string $group): string
    {
        foreach (self::CATEGORIES as $cat => $groups) {
            if (in_array($group, $groups, true)) {
                return $cat;
            }
        }

        return '其他';
    }

    /** 領域 autonomy 的有效值（帳號覆寫 > 全域覆寫 > 領域包預設）。 */
    public function domainAutonomy(string $domain, string $default, ?int $userId = null): string
    {
        $v = $this->get("domain.{$domain}.autonomy", null, $userId);

        return in_array($v, ['copilot', 'supervisor', 'autopilot'], true) ? $v : $default;
    }

    /**
     * 後台設定頁要顯示的欄位 + 目前值。
     *
     * @return list<array<string, mixed>>
     */
    /** 只有 admin 能看/設的「平台層級」設定（LLM/語音基礎建設、共用密鑰）—— 非 admin 設定頁不顯示。 */
    public const ADMIN_ONLY = [
        'llm.provider', 'llm.base_url', 'llm.api_key', 'llm.model',
        'llm.small_model', 'llm.small_base_url', 'llm.small_api_key',
        'voice.stt_url', 'voice.fullduplex_enabled', 'voice.fullduplex_url', 'voice.fullduplex_path', 'voice.agent_secret',
        // Discord/Slack 是平台層級單一 bot → admin 設定
        'discord.app_id', 'discord.public_key',
        'slack.signing_secret', 'slack.bot_token',
        'feishu.app_id', 'feishu.app_secret', 'feishu.verification_token',
        'dingtalk.app_secret',
        'mattermost.token', 'mattermost.base_url', 'mattermost.bot_token',
        'twilio.account_sid', 'twilio.auth_token', 'twilio.from',
        'onebot.secret', 'onebot.api_url', 'onebot.api_token',
        'bluebubbles.server_url', 'bluebubbles.password',
        'signal.api_url', 'signal.number',
        // 通勤地理服務端點屬平台共用基建
        'commute.geocode_url', 'commute.osrm_url',
    ];

    /** 「每個帳號各自獨立」的設定（通知頻道）：顯示與讀取都只看自己的，不 fallback 全域，才能完全分開。 */
    public const USER_SCOPED = [
        'notify.webhook_url', 'notify.telegram.token', 'notify.telegram.chat_id',
        'notify.line.secret', 'notify.line.token', 'notify.line.to',
        // 供應商金鑰：每個帳號用自己的（生圖/Firecrawl/FAL）
        'image.api_url', 'image.api_key', 'image.model',
        'firecrawl.api_key', 'fal.api_key',
        // 通勤遲到提醒：每帳號自己的公司/主管/上班時間
        'commute.enabled', 'commute.work_place', 'commute.work_start', 'commute.work_days', 'commute.nav_app', 'commute.lead_min', 'commute.radius_m',
        'commute.manager_via', 'commute.manager_to', 'commute.manager_name', 'commute.message_template',
        'notify.default_platform', 'contacts.map',
        // 語音音色：每帳號自己選引擎/音色
        'voice.tts_engine', 'voice.tts_speaker',
        // 行程出發提醒 + 主動思考：每帳號自己決定要不要開、頻率、安靜時段
        'event_guard.enabled', 'event_guard.lead_min',
        'proactive.enabled', 'proactive.every_min', 'proactive.quiet',
    ];

    /** 只讀「該帳號自己設的」值（不 fallback 全域）；userId=null 才回全域。 */
    public function own(string $key, ?int $userId): mixed
    {
        try {
            $row = PaiSetting::find($userId !== null ? "u{$userId}:{$key}" : $key);

            return $row?->value;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  bool  $isAdmin  非 admin → 隱藏 ADMIN_ONLY 平台/密鑰欄位 */
    public function editableFields(?int $userId = null, bool $isAdmin = true): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $meta) {
            if (! $isAdmin && in_array($key, self::ADMIN_ONLY, true)) {
                continue;
            }
            // 每帳號各自獨立的設定：只顯示自己設的（不 fallback 全域，否則會看到別人的）；
            // 沿用各自原本的 group / 分類（通知→通知；生圖/供應商→供應商）。
            if (in_array($key, self::USER_SCOPED, true)) {
                $out[] = [...$meta, 'key' => $key, 'value' => $this->own($key, $userId), 'category' => self::categoryFor($meta['group'])];

                continue;
            }
            // LLM 供應商：選單帶入所有 preset（label 附端點/模型提示）
            if ($key === 'llm.provider') {
                $meta['options'] = array_map(
                    fn ($k, $p) => ['value' => $k, 'label' => $p['label'].($p['base_url'] ? "（{$p['base_url']}）" : '')],
                    array_keys(self::PROVIDERS), self::PROVIDERS
                );
            }
            // 預設操作節點：選單動態帶入「主節點 + 已註冊的 gateway 節點」
            if ($key === 'voice.default_gateway') {
                $opts = [['value' => 'local', 'label' => '主節點（本機）']];
                try {
                    // 只列「這個帳號可存取」的節點（admin → 全部；非 admin → 自己擁有/被授權的）
                    $owner = $userId ? \App\Models\User::find($userId) : null;
                    $allowed = ($owner && ! $owner->isAdmin()) ? $owner->allowedDeviceNames() : null;
                    foreach (\App\Pai\Mcp\McpServer::query()->get(['name', 'enabled']) as $s) {
                        if ($s->name !== 'gateway' && ($allowed === null || in_array($s->name, $allowed, true))) {
                            $opts[] = ['value' => $s->name, 'label' => $s->name.($s->enabled ? '' : '（離線）')];
                        }
                    }
                } catch (Throwable) {
                    // mcp_servers 表不存在 → 只給 local
                }
                $meta['options'] = $opts;
            }
            $out[] = [...$meta, 'key' => $key, 'value' => $this->get($key, null, $userId), 'category' => self::categoryFor($meta['group'])];
        }

        return $out;
    }
}
