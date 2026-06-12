<?php

namespace App\Pai\Chat;

use App\Models\User;
use App\Pai\Settings\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * 通用管道回覆：SMS(Twilio) / QQ(OneBot) / BlueBubbles / Signal 共用。
 * 對話大腦回應後，依 $channel 用對應的 HTTP API 把答案發回去。帶啟用人格+記憶。
 */
class GenericChannelReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /** @param  array<string,mixed>  $meta  各管道回覆所需的目標資訊 */
    public function __construct(public string $channel, public string $key, public string $text, public array $meta = [])
    {
        $this->onQueue('chat');
    }

    public function handle(ChatResponder $responder, Settings $settings): void
    {
        $sid = $this->channel.':'.$this->key;
        $ownerId = User::where('role', 'admin')->orderBy('id')->min('id') ?? User::query()->min('id');
        $conv = Conversation::where('voice_sid', $sid)->latest('id')->first()
            ?? Conversation::create(['voice_sid' => $sid, 'user_id' => $ownerId, 'title' => Str::limit($this->text, 30)]);

        $conv->addMessage('user', $this->text, ['source' => $this->channel]);
        $reply = '（沒有產生回覆）';
        try {
            $r = $responder->respond($conv, $this->text);
            $reply = $r['reply'] ?: $reply;
        } catch (Throwable $e) {
            $reply = '抱歉，這次處理失敗了：'.$e->getMessage();
        }
        $conv->addMessage('assistant', $reply, ['source' => $this->channel]);
        \App\Pai\Memory\ExtractMemoryJob::dispatch($this->text, $reply, $conv->user_id);

        try {
            $this->send($settings, $reply);
        } catch (Throwable) {
        }
    }

    private function send(Settings $settings, string $reply): void
    {
        $reply = mb_substr($reply, 0, 3500);
        switch ($this->channel) {
            case 'sms':
                $sid = (string) $settings->get('twilio.account_sid', '');
                $token = (string) $settings->get('twilio.auth_token', '');
                $from = (string) $settings->get('twilio.from', '');
                if ($sid && $token && $from) {
                    Http::timeout(20)->asForm()->withBasicAuth($sid, $token)
                        ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
                            ['From' => $from, 'To' => (string) ($this->meta['from'] ?? $this->key), 'Body' => mb_substr($reply, 0, 1500)]);
                }
                break;
            case 'onebot': // QQ
                $api = rtrim((string) $settings->get('onebot.api_url', ''), '/');
                $apiToken = (string) $settings->get('onebot.api_token', '');
                if ($api !== '') {
                    $payload = (($this->meta['message_type'] ?? '') === 'group')
                        ? ['message_type' => 'group', 'group_id' => $this->meta['group_id'] ?? null, 'message' => $reply]
                        : ['message_type' => 'private', 'user_id' => $this->meta['user_id'] ?? $this->key, 'message' => $reply];
                    $req = Http::timeout(20);
                    if ($apiToken !== '') {
                        $req = $req->withToken($apiToken);
                    }
                    $req->post($api.'/send_msg', $payload);
                }
                break;
            case 'bluebubbles': // iMessage
                $url = rtrim((string) $settings->get('bluebubbles.server_url', ''), '/');
                $pw = (string) $settings->get('bluebubbles.password', '');
                if ($url !== '' && isset($this->meta['chat_guid'])) {
                    Http::timeout(20)->post($url.'/api/v1/message/text?password='.urlencode($pw), [
                        'chatGuid' => $this->meta['chat_guid'], 'message' => $reply, 'method' => 'private-api',
                    ]);
                }
                break;
            case 'signal':
                $url = rtrim((string) $settings->get('signal.api_url', ''), '/');
                $number = (string) $settings->get('signal.number', '');
                if ($url !== '' && $number !== '') {
                    Http::timeout(20)->post($url.'/v2/send', [
                        'number' => $number, 'recipients' => [$this->key], 'message' => $reply,
                    ]);
                }
                break;
        }
    }
}
