<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PacksControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $savedPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'T', 'email' => 't@pai.test', 'password' => bcrypt('x')]));
    }

    protected function tearDown(): void
    {
        if ($this->savedPath && file_exists($this->savedPath)) {
            @unlink($this->savedPath);
        }
        parent::tearDown();
    }

    public function test_generate_stores_preview_in_session(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($this->manifest())], 'finish_reason' => 'stop']],
            'usage' => [],
        ])]);

        $this->post('/packs/generate', ['description' => '測試領域'])
            ->assertRedirect('/packs')
            ->assertSessionHas('pack_preview');
    }

    public function test_save_writes_pack_file(): void
    {
        $domain = 'nl-savetest';
        $this->savedPath = base_path("packs/{$domain}.yaml");
        $yaml = Yaml::dump($this->manifest($domain));

        $this->post('/packs/save', ['yaml' => $yaml])->assertRedirect('/packs');

        $this->assertFileExists($this->savedPath);
        $this->assertStringContainsString($domain, file_get_contents($this->savedPath));
    }

    public function test_save_rejects_invalid_manifest(): void
    {
        $this->post('/packs/save', ['yaml' => "domain: bad\ncoordinator: bad\n"])
            ->assertRedirect()
            ->assertSessionHas('flash');
        $this->assertFileDoesNotExist(base_path('packs/bad.yaml'));
    }

    private function manifest(string $domain = 'nl-demo'): array
    {
        return [
            'domain' => $domain,
            'coordinator' => $domain.'-coordinator',
            'description' => '測試',
            'triggers' => ['events' => ['demo.event']],
            'tools' => [['uri' => 'mcp://demo', 'perms' => ['read']]],
            'agents' => ['topology' => 'router', 'roster' => [['name' => 'worker', 'role' => '做事']]],
            'memory' => ['namespace' => $domain, 'knowledge' => [['type' => 'vector', 'source' => 'kb']]],
            'risk_policy' => ['autonomy' => 'supervisor', 'hitl_required' => []],
            'contracts' => ['output' => 'contracts/Demo.schema.json'],
        ];
    }
}
