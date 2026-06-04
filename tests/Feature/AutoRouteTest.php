<?php

namespace Tests\Feature;

use App\Pai\Cognition\MetaRouter;
use App\Pai\Cognition\RouteCommandJob;
use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Perception\PaiEvent;
use App\Pai\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoRouteTest extends TestCase
{
    use RefreshDatabase;

    private function resp(array $obj): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode($obj, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
            'usage' => [],
        ];
    }

    private function event(string $message): PaiEvent
    {
        return PaiEvent::create(['source' => 'console', 'topic' => 'console.request', 'payload' => ['message' => $message], 'status' => 'received']);
    }

    public function test_meta_router_classifies(): void
    {
        Http::fake(['*' => Http::response($this->resp(['category' => 'new_domain', 'reason' => 'x']))]);
        $this->assertSame('new_domain', $this->app->make(MetaRouter::class)->classify('監控 X 並自動 Y')['category']);
    }

    public function test_task_is_routed_to_domain_and_coordinator(): void
    {
        Bus::fake([RunCoordinatorJob::class]);
        Http::fakeSequence()
            ->push($this->resp(['category' => 'task', 'reason' => '處理事件']))
            ->push($this->resp(['domain' => 'sec-ir', 'topic' => 'siem.alert', 'severity' => 'high', 'rationale' => '勒索']));

        $e = $this->event('主機中了勒索病毒幫我處理');
        RouteCommandJob::dispatchSync($e->id);

        $e->refresh();
        $this->assertSame('sec-ir', $e->domain);
        $this->assertSame('routed', $e->status->value);
        Bus::assertDispatched(RunCoordinatorJob::class, fn ($j) => $j->eventId === $e->id);
    }

    public function test_new_domain_generates_and_saves_pack(): void
    {
        $path = base_path('packs/auto-route-demo.yaml');
        @unlink($path);

        Http::fakeSequence()
            ->push($this->resp(['category' => 'new_domain', 'reason' => '新領域']))
            ->push($this->resp([
                'domain' => 'auto-route-demo', 'coordinator' => 'auto-route-demo-coordinator', 'description' => '測試',
                'triggers' => ['events' => ['x.y']],
                'tools' => [['uri' => 'mcp://x', 'perms' => ['read']]],
                'agents' => ['topology' => 'router', 'roster' => [['name' => 'w', 'role' => 'r']]],
                'memory' => ['namespace' => 'auto-route-demo', 'knowledge' => [['type' => 'vector', 'source' => 'k']]],
                'risk_policy' => ['autonomy' => 'supervisor', 'hitl_required' => []],
                'contracts' => ['output' => 'contracts/X.schema.json'],
            ]));

        $e = $this->event('監控資料庫慢查詢並建議加索引');
        try {
            RouteCommandJob::dispatchSync($e->id);
            $this->assertFileExists($path);
            $this->assertSame('normalized', $e->refresh()->status->value);
        } finally {
            @unlink($path);
        }
    }

    public function test_configure_notify_saves_settings(): void
    {
        Http::fakeSequence()
            ->push($this->resp(['category' => 'configure_notify', 'reason' => '設定通知']))
            ->push($this->resp(['channel' => 'telegram', 'token' => 'TKN', 'chat_id' => 'CID', 'reply' => '已設定']));

        $e = $this->event('我的 telegram token 是 TKN chat id CID');
        RouteCommandJob::dispatchSync($e->id);

        $this->assertSame('TKN', $this->app->make(Settings::class)->get('notify.telegram.token'));
        $this->assertSame('configure-notify', $e->refresh()->intent);
    }
}
