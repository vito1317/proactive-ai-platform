<?php

namespace Tests\Feature;

use App\Pai\Security\EgressGateway;
use App\Pai\Security\SecretRef;
use App\Pai\Security\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZeroTrustEgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_vault_round_trips_and_hides_value(): void
    {
        $vault = $this->app->make(SecretVault::class);
        $vault->put('siem_token', 'SECRET-XYZ', '測試');

        $this->assertTrue($vault->has('siem_token'));
        $this->assertSame('SECRET-XYZ', $vault->get('siem_token'));
        $this->assertSame(['siem_token'], $vault->names());

        // DB 內存的是密文，非明文
        $this->assertDatabaseMissing('pai_secrets', ['ciphertext' => 'SECRET-XYZ']);
    }

    public function test_secret_injected_at_egress_only(): void
    {
        $this->app->make(SecretVault::class)->put('siem_token', 'SECRET-XYZ');
        Http::fake(['*' => Http::response(['ok' => true])]);

        // 智能體端只構造佔位符——從不接觸明文
        $agentPayload = ['action' => 'query', 'token' => SecretRef::placeholder('siem_token')];
        $this->assertStringNotContainsString('SECRET-XYZ', json_encode($agentPayload));

        $this->app->make(EgressGateway::class)->client()
            ->withHeaders(['Authorization' => 'Bearer '.SecretRef::placeholder('siem_token')])
            ->post('https://siem.example/api', $agentPayload);

        // 送出的請求才帶真正憑證（網路層注入）
        Http::assertSent(function ($request) {
            return $request->header('Authorization')[0] === 'Bearer SECRET-XYZ'
                && str_contains($request->body(), 'SECRET-XYZ')
                && ! str_contains($request->body(), '{{vault:');
        });
    }

    public function test_unknown_reference_is_left_as_placeholder(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        $this->app->make(EgressGateway::class)->client()
            ->withHeaders(['Authorization' => 'Bearer '.SecretRef::placeholder('does_not_exist')])
            ->get('https://x.example');

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer {{vault:does_not_exist}}');
    }
}
