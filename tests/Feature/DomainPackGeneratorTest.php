<?php

namespace Tests\Feature;

use App\Pai\Domains\DomainPackGenerator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainPackGeneratorTest extends TestCase
{
    private function validManifest(string $domain = 'nl-demo'): array
    {
        return [
            'domain' => $domain,
            'coordinator' => $domain.'-coordinator',
            'description' => '測試領域',
            'triggers' => ['events' => ['demo.event']],
            'tools' => [['uri' => 'mcp://demo', 'perms' => ['read']]],
            'agents' => ['topology' => 'router', 'roster' => [['name' => 'worker', 'role' => '做事']]],
            'memory' => ['namespace' => $domain, 'knowledge' => [['type' => 'vector', 'source' => 'kb']]],
            'risk_policy' => ['autonomy' => 'supervisor', 'hitl_required' => []],
            'contracts' => ['output' => 'contracts/Demo.schema.json'],
        ];
    }

    private function llmResponse(array $manifest): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode($manifest, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 10],
        ];
    }

    public function test_generates_valid_pack_from_description(): void
    {
        Http::fake(['*' => Http::response($this->llmResponse($this->validManifest()))]);

        $result = $this->app->make(DomainPackGenerator::class)->generate('一個示範領域');

        $this->assertTrue($result['valid']);
        $this->assertSame('nl-demo', $result['manifest']['domain']);
        $this->assertStringContainsString('nl-demo', $result['yaml']);
        $this->assertSame([], $result['errors']);
    }

    public function test_retries_on_invalid_then_succeeds(): void
    {
        $invalid = $this->validManifest();
        unset($invalid['domain']); // 缺必填 → 第一次驗證失敗

        Http::fakeSequence()
            ->push($this->llmResponse($invalid))
            ->push($this->llmResponse($this->validManifest()));

        $result = $this->app->make(DomainPackGenerator::class)->generate('修正後應通過');

        $this->assertTrue($result['valid']);
        $this->assertSame('nl-demo', $result['manifest']['domain']);
    }
}
