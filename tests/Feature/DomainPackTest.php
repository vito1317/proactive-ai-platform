<?php

namespace Tests\Feature;

use App\Pai\Domains\DomainPackLoader;
use App\Pai\Domains\DomainPackValidationException;
use App\Pai\Domains\DomainPackValidator;
use App\Pai\Domains\DomainRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DomainPackTest extends TestCase
{
    /** 一份結構正確的最小 manifest，供「合法基準」與「逐項破壞」測試。 */
    private function validManifest(): array
    {
        return [
            'domain' => 'demo',
            'coordinator' => 'demo-coordinator',
            'description' => '示範領域',
            'triggers' => ['events' => ['demo.event']],
            'tools' => [
                ['uri' => 'mcp://demo', 'perms' => ['read']],
                ['uri' => 'mcp://demo', 'perms' => ['write'], 'risk' => 'high'],
            ],
            'agents' => [
                'topology' => 'router',
                'roster' => [['name' => 'worker', 'role' => '做事']],
            ],
            'memory' => [
                'namespace' => 'demo',
                'knowledge' => [['type' => 'vector', 'source' => 'demo-kb']],
            ],
            'risk_policy' => [
                'autonomy' => 'supervisor',
                'hitl_required' => ['dangerous-action'],
                'rate_limits' => ['dangerous-action' => '5/min'],
            ],
            'contracts' => ['output' => 'contracts/Demo.schema.json'],
        ];
    }

    public function test_real_packs_load_into_registry(): void
    {
        $registry = $this->app->make(DomainRegistry::class);

        $this->assertSame([], $registry->errors(), '真實領域包不應有驗證錯誤');
        $this->assertGreaterThanOrEqual(3, $registry->count());
        $this->assertTrue($registry->has('log-ops'));
        $this->assertTrue($registry->has('sec-ir'));
        $this->assertTrue($registry->has('dev-auto'));

        $secir = $registry->get('sec-ir');
        $this->assertSame('supervisor', $secir->autonomy);
        $this->assertCount(3, $secir->highRiskTools());
        $this->assertTrue($secir->isHitlAction('isolate-host'));
        $this->assertFalse($secir->isHitlAction('read-logs'));
    }

    public function test_registry_routes_events_to_domains(): void
    {
        $registry = $this->app->make(DomainRegistry::class);

        $secir = $registry->forEvent('siem.alert');
        $this->assertCount(1, $secir);
        $this->assertSame('sec-ir', $secir[0]->domain);

        $devauto = $registry->forEvent('ci.failed');
        $this->assertCount(1, $devauto);
        $this->assertSame('dev-auto', $devauto[0]->domain);

        $this->assertSame([], $registry->forEvent('nonexistent.topic'));
    }

    public function test_validator_accepts_valid_manifest(): void
    {
        $errors = (new DomainPackValidator)->validate($this->validManifest());
        $this->assertSame([], $errors);
    }

    #[DataProvider('invalidMutations')]
    public function test_validator_rejects_invalid_manifest(callable $mutate, string $expectFragment): void
    {
        $m = $this->validManifest();
        $mutate($m);
        $errors = (new DomainPackValidator)->validate($m);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString(
            $expectFragment,
            implode(' | ', $errors),
            ' 預期錯誤訊息含: '.$expectFragment,
        );
    }

    public static function invalidMutations(): array
    {
        return [
            '缺 domain' => [fn (&$m) => $m = array_diff_key($m, ['domain' => 1]), 'domain'],
            'domain 非 kebab' => [fn (&$m) => $m['domain'] = 'Demo_Domain', 'kebab'],
            '非法 autonomy' => [fn (&$m) => $m['risk_policy']['autonomy'] = 'godmode', 'autonomy'],
            'tool uri 非 mcp' => [fn (&$m) => $m['tools'][0]['uri'] = 'http://x', 'mcp://'],
            'triggers 全空' => [fn (&$m) => $m['triggers'] = ['events' => [], 'cron' => []], 'events 或 cron'],
            'topology 非法' => [fn (&$m) => $m['agents']['topology'] = 'mesh', 'topology'],
            'contract 非 schema.json' => [fn (&$m) => $m['contracts']['output'] = 'x.json', 'schema.json'],
            'rate_limit 格式錯' => [fn (&$m) => $m['risk_policy']['rate_limits'] = ['a' => '5 per minute'], 'rate_limits'],
        ];
    }

    public function test_loader_lenient_skips_invalid_files(): void
    {
        $dir = sys_get_temp_dir().'/pai-packs-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/good.yaml', Yaml::dump($this->validManifest()));
        file_put_contents($dir.'/bad.yaml', "domain: bad\ncoordinator: bad\n"); // 缺一堆必填

        $loader = new DomainPackLoader(new DomainPackValidator, $dir);
        $result = $loader->loadAllLenient();

        $this->assertCount(1, $result['packs']);
        $this->assertArrayHasKey('demo', $result['packs']);
        $this->assertArrayHasKey('bad.yaml', $result['errors']);

        // 嚴格模式應拋出
        $this->expectException(DomainPackValidationException::class);
        $loader->loadFile($dir.'/bad.yaml');
    }
}
