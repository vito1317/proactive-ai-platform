<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pai\Cognition\AgentRun;
use App\Pai\Cognition\RunStatus;
use App\Pai\Notify\PushNotifier;
use App\Pai\Perception\PaiEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PushNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): AgentRun
    {
        $event = PaiEvent::create(['source' => 'siem', 'topic' => 'siem.alert', 'payload' => [], 'status' => 'routed']);

        return AgentRun::create([
            'event_id' => $event->id, 'domain' => 'sec-ir', 'coordinator' => 'sec-ir-coordinator',
            'status' => RunStatus::AwaitingHitl,
            'actions' => [['action' => 'isolate-host', 'rationale' => 'r', 'risk' => 'high', 'status' => 'awaiting_approval']],
        ]);
    }

    public function test_notifies_users_and_dedupes(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@pai.test', 'password' => Hash::make('x')]);
        $run = $this->makeRun();

        $notifier = $this->app->make(PushNotifier::class);
        $notifier->hitlNeeded($run);

        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());
        $this->assertStringContainsString('待核准', $user->notifications()->first()->data['message']);

        // 重複呼叫（resume）→ 不再新增
        $notifier->hitlNeeded($run);
        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());
    }
}
