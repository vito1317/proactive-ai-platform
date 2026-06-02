<?php

namespace App\Http\Controllers;

use App\Pai\Notify\Notifier;
use App\Pai\Notify\NotifyAssistant;
use App\Pai\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 通知平台設定：AI 自然語言引導 + 測試推播。
 */
class NotifyController extends Controller
{
    /** 用自然語言引導設定 Telegram / LINE / webhook。 */
    public function assist(Request $request, NotifyAssistant $assistant, Settings $settings, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:1000']]);

        $result = $assistant->extract($data['message']);

        // 存入抓到的設定
        foreach ($result['fields'] as $key => $value) {
            $settings->set($key, $value);
        }

        $msg = $result['reply'] ?: '已處理。';

        // 若該通道已設定完整 → 立即發測試訊息
        if ($result['fields'] !== [] && ($notifier->configured()[$result['channel']] ?? false)) {
            $sent = $notifier->send('✅ PAI 通知測試：設定成功，你會在這裡收到待核准提醒。');
            $ok = ! empty(array_filter($sent));
            $msg .= $ok ? '　已發送測試訊息，請查收。' : '　（測試訊息發送失敗，請檢查 token/目標）';
        }

        return back()->with('flash', ['success' => $msg]);
    }

    /** 對所有已設定通道發測試訊息。 */
    public function test(Notifier $notifier): RedirectResponse
    {
        $sent = array_keys(array_filter($notifier->send('🔔 PAI 測試通知：通道運作正常。')));

        return back()->with('flash', [
            'success' => $sent === [] ? '尚無已設定的通知通道。' : '已發送測試到：'.implode('、', $sent),
        ]);
    }
}
