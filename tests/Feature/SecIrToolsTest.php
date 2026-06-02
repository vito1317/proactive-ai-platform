<?php

namespace Tests\Feature;

use App\Pai\Cognition\AgentContext;
use App\Pai\Cognition\Tools\SecIr\LookupAttackTechniqueTool;
use App\Pai\Cognition\Tools\SecIr\OpenIncidentTicketTool;
use App\Pai\Cognition\Tools\SecIr\ProposeContainmentTool;
use App\Pai\Cognition\Tools\SecIr\QueryEndpointTool;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Perception\PaiEvent;
use App\Pai\Security\EgressGateway;
use App\Pai\Security\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecIrToolsTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(array $payload = ['host' => '10.0.0.5', 'rule' => 'brute-force']): AgentContext
    {
        $pack = $this->app->make(DomainRegistry::class)->get('sec-ir');
        $event = PaiEvent::create([
            'source' => 'siem', 'topic' => 'siem.alert', 'payload' => $payload,
            'intent' => 'security-alert', 'severity' => 'high', 'domain' => 'sec-ir', 'status' => 'routed',
        ]);

        return new AgentContext($event, $pack);
    }

    public function test_lookup_attack_technique(): void
    {
        $tool = new LookupAttackTechniqueTool;
        $this->assertStringContainsString('T1110', $tool->run(['keyword' => 'brute-force'], $this->ctx())->observation);
        $this->assertStringContainsString('T1486', $tool->run(['keyword' => 'ransomware attack'], $this->ctx())->observation);
    }

    public function test_query_endpoint_simulated_when_no_url(): void
    {
        $tool = new QueryEndpointTool($this->app->make(EgressGateway::class), null);
        $r = $tool->run(['host' => '10.0.0.9'], $this->ctx());
        $this->assertTrue($r->ok);
        $this->assertStringContainsString('模擬遙測', $r->observation);
        $this->assertStringContainsString('10.0.0.9', $r->observation);
    }

    public function test_query_endpoint_injects_credential_at_egress(): void
    {
        $this->app->make(SecretVault::class)->put('edr_token', 'EDR-SECRET');
        Http::fake(['*' => Http::response('{"verdict":"malicious"}')]);

        $tool = new QueryEndpointTool($this->app->make(EgressGateway::class), 'https://edr.example/api');
        $tool->run(['host' => '10.0.0.5'], $this->ctx());

        Http::assertSent(fn ($req) => $req->header('Authorization')[0] === 'Bearer EDR-SECRET'
            && str_contains($req->url(), 'host=10.0.0.5'));
    }

    public function test_open_incident_ticket_is_medium_risk(): void
    {
        $ctx = $this->ctx();
        $r = (new OpenIncidentTicketTool)->run(['summary' => '暴力破解告警'], $ctx);
        $this->assertTrue($r->ok);
        $this->assertSame('medium', $ctx->actions[0]['risk']);
        $this->assertStringContainsString('open-ticket:INC-', $ctx->actions[0]['action']);
    }

    public function test_propose_containment_is_high_risk_and_validated(): void
    {
        $ctx = $this->ctx();
        $ok = (new ProposeContainmentTool)->run(['action' => 'isolate-host', 'target' => 'host-7', 'rationale' => '勒索'], $ctx);
        $this->assertTrue($ok->ok);
        $this->assertSame('isolate-host', $ctx->actions[0]['action']);
        $this->assertSame('high', $ctx->actions[0]['risk']);

        $bad = (new ProposeContainmentTool)->run(['action' => 'nuke-everything'], $ctx);
        $this->assertFalse($bad->ok);
    }
}
