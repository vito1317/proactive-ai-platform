<?php

namespace Tests\Feature;

use App\Pai\Cognition\AgentContext;
use App\Pai\Mcp\McpClient;
use App\Pai\Mcp\McpManager;
use App\Pai\Mcp\McpServer;
use App\Pai\Mcp\McpTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMcp(array $tools): void
    {
        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2024-11-05', 'capabilities' => [], 'serverInfo' => ['name' => 's']]])
            ->push('', 202) // notifications/initialized
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => $tools]]);
    }

    public function test_add_connects_validates_and_caches_tools(): void
    {
        $this->fakeMcp([['name' => 'echo', 'description' => 'echo back', 'inputSchema' => ['type' => 'object', 'properties' => ['text' => []]]]]);

        $res = $this->app->make(McpManager::class)->add('demo', 'https://mcp.example.com/rpc', ['Authorization' => 'Bearer x']);

        $this->assertTrue($res['ok']);
        $server = McpServer::where('name', 'demo')->first();
        $this->assertNotNull($server);
        $this->assertTrue($server->enabled);
        $this->assertSame('echo', $server->tools[0]['name']);
    }

    public function test_add_records_but_disables_on_failure(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $res = $this->app->make(McpManager::class)->add('bad', 'https://nope.example.com/rpc');

        $this->assertFalse($res['ok']);
        $this->assertFalse(McpServer::where('name', 'bad')->first()->enabled);
    }

    public function test_manager_exposes_enabled_tools_as_react_tools(): void
    {
        McpServer::create([
            'name' => 'demo', 'url' => 'https://mcp.example.com/rpc', 'enabled' => true,
            'tools' => [['name' => 'search', 'description' => 'web search', 'inputSchema' => ['properties' => ['q' => []]]]],
        ]);

        $tools = $this->app->make(McpManager::class)->tools();
        $this->assertCount(1, $tools);
        $this->assertInstanceOf(McpTool::class, $tools[0]);
        $this->assertSame('mcp__demo__search', $tools[0]->name());
        $this->assertStringContainsString('web search', $tools[0]->description());
    }

    public function test_mcp_tool_calls_and_returns_text(): void
    {
        $server = McpServer::create(['name' => 'demo', 'url' => 'https://mcp.example.com/rpc', 'enabled' => true, 'tools' => []]);
        Http::fakeSequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['capabilities' => []]])
            ->push('', 202)
            ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [['type' => 'text', 'text' => 'hello from mcp']]]]);

        $tool = new McpTool($this->app->make(McpClient::class), $server, ['name' => 'echo']);
        $ctx = (new \ReflectionClass(AgentContext::class))->newInstanceWithoutConstructor();
        $result = $tool->run(['text' => 'hi'], $ctx);

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('hello from mcp', $result->observation);
    }

    public function test_client_parses_sse_framed_response(): void
    {
        Http::fakeSequence()
            ->push("event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['capabilities' => []]])."\n\n")
            ->push('', 202)
            ->push("event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'sse_tool']]]])."\n\n");

        $res = $this->app->make(McpClient::class)->listTools('https://mcp.example.com/rpc', []);

        $this->assertTrue($res['ok']);
        $this->assertSame('sse_tool', $res['tools'][0]['name']);
    }
}
