<?php

namespace App\Http\Controllers;

use App\Pai\Notify\Notifier;
use App\Pai\Notify\NotifyAssistant;
use App\Pai\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 通知平台設定：AI 自然語言引導 + 測試推播（含 Telegram chat id 自動偵測）。
 */
class NotifyController extends Controller
{
    public function assist(Request $request, NotifyAssistant $assistant, Settings $settings, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:1000']]);
        $result = $assistant->extract($data['message']);

        foreach ($result['fields'] as $key => $value) {
            $settings->set($key, $value);
        }

        $msg = $result['reply'] ?: '已處理。';
        if ($result['fields'] !== []) {
            $msg .= '　'.$this->attempt($notifier, $settings)['summary'];
        }

        return back()->with('flash', ['success' => $msg]);
    }

    public function test(Notifier $notifier, Settings $settings): RedirectResponse
    {
        $r = $this->attempt($notifier, $settings);

        return back()->with('flash', [($r['ok'] ? 'success' : 'error') => $r['summary']]);
    }

    /**
     * 對所有已設定通道發測試；Telegram 失敗時嘗試自動偵測 chat id 並重送。
     *
     * @return array{ok: bool, summary: string}
     */
    private function attempt(Notifier $notifier, Settings $settings): array
    {
        $results = $notifier->dispatch('🔔 PAI 測試通知：通道運作正常。');
        $configured = array_filter($results, fn ($r) => $r['configured']);

        if ($configured === []) {
            return ['ok' => false, 'summary' => '尚無已設定的通知通道，請先提供 Telegram / LINE / Webhook 的資訊。'];
        }

        $lines = [];
        $allOk = true;
        foreach ($configured as $ch => $r) {
            if ($r['ok']) {
                $lines[] = "✅ {$ch}";

                continue;
            }
            // Telegram：自動偵測正確 chat id 後重送
            if ($ch === 'telegram' && ($id = $notifier->detectTelegramChatId())) {
                $settings->set('notify.telegram.chat_id', $id);
                if (($notifier->dispatch('🔔 PAI 測試通知（已自動修正 chat id）。')['telegram']['ok'] ?? false)) {
                    $lines[] = "✅ telegram（已自動偵測並修正 chat id 為 {$id}）";

                    continue;
                }
            }
            $allOk = false;
            $hint = $ch === 'telegram' ? '；請先用你的帳號對 bot 傳一則訊息再測試（系統會自動抓 chat id）' : '';
            $lines[] = "❌ {$ch}（{$r['error']}）{$hint}";
        }

        return ['ok' => $allOk, 'summary' => implode('　', $lines)];
    }
}
