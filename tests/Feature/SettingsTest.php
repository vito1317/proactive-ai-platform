<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pai\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'T', 'email' => 't@pai.test', 'password' => bcrypt('x')]));
    }

    public function test_settings_page_renders(): void
    {
        $this->get('/settings')->assertOk();
    }

    public function test_update_persists_and_overrides_config(): void
    {
        $this->post('/settings', [
            'settings' => ['llm.max_tokens' => 4096, 'react.reflect' => false],
            'autonomy' => ['dev-auto' => 'supervisor'],
        ])->assertRedirect();

        $this->assertDatabaseHas('pai_settings', ['key' => 'llm.max_tokens']);

        $s = $this->app->make(Settings::class);
        $this->assertSame(4096, (int) $s->get('llm.max_tokens'));
        $this->assertFalse($s->get('react.reflect'));
        $this->assertSame('supervisor', $s->domainAutonomy('dev-auto', 'copilot'));
    }

    public function test_get_falls_back_to_config_default(): void
    {
        $s = $this->app->make(Settings::class);
        // 未覆寫時應回 config/pai.php 預設
        $this->assertSame(config('pai.llm.base_url'), $s->get('llm.base_url'));
    }

    public function test_unknown_setting_key_is_ignored(): void
    {
        $this->post('/settings', ['settings' => ['evil.key' => 'x']])->assertRedirect();
        $this->assertDatabaseMissing('pai_settings', ['key' => 'evil.key']);
    }
}
