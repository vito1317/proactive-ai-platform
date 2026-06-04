<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@pai.test', 'password' => Hash::make('secret123')]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_login_with_valid_credentials(): void
    {
        $this->user();
        $this->post('/login', ['email' => 'admin@pai.test', 'password' => 'secret123'])
            ->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_login_with_invalid_credentials_fails(): void
    {
        $this->user();
        $this->post('/login', ['email' => 'admin@pai.test', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_see_console(): void
    {
        $this->actingAs($this->user())->get('/')->assertOk();
    }

    public function test_webhooks_remain_public(): void
    {
        Bus::fake(); // 不跑 ingest 後續
        // 未登入仍可推事件（不被導去 /login）
        $this->postJson('/webhooks/siem', ['topic' => 'siem.alert'])->assertStatus(202);
    }
}
