<?php

namespace App\Http\Controllers;

use App\Pai\Domains\DomainRegistry;
use App\Pai\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 後台設定：所有平台參數（LLM / ReAct / 各領域 autonomy）皆可從 UI 調整，
 * 覆寫 config 預設並即時生效。
 */
class SettingsController extends Controller
{
    public function index(Settings $settings, DomainRegistry $registry): Response
    {
        $domains = array_map(static fn ($p) => [
            'domain' => $p->domain,
            'default' => $p->autonomy,
            'effective' => $settings->domainAutonomy($p->domain, $p->autonomy),
        ], array_values($registry->all()));

        return Inertia::render('Settings', [
            'fields' => $settings->editableFields(),
            'domains' => $domains,
            'autonomyLevels' => config('pai.autonomy_levels'),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'settings' => ['array'],
            'autonomy' => ['array'],
            'autonomy.*' => ['in:copilot,supervisor,autopilot'],
        ]);

        foreach (($data['settings'] ?? []) as $key => $value) {
            if (! array_key_exists($key, Settings::FIELDS)) {
                continue; // 只接受已知欄位
            }
            $settings->set($key, $this->coerce(Settings::FIELDS[$key]['type'], $value));
        }

        foreach (($data['autonomy'] ?? []) as $domain => $level) {
            $settings->set("domain.{$domain}.autonomy", $level);
        }

        return back()->with('flash', ['success' => '設定已儲存，即時生效。']);
    }

    private function coerce(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'number' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }
}
