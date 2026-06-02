<?php

namespace Tests\Feature;

use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Perception\CronTrigger;
use App\Pai\Perception\EventStatus;
use App\Pai\Perception\PaiEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CronTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fire_creates_routed_event_and_dispatches_coordinator(): void
    {
        Bus::fake([RunCoordinatorJob::class]);

        $event = $this->app->make(CronTrigger::class)->fire('sec-ir', '每日威脅情報彙整');

        $this->assertNotNull($event);
        $this->assertSame('cron', $event->source);
        $this->assertSame('sec-ir', $event->domain);
        $this->assertSame(EventStatus::Routed, $event->status);
        $this->assertSame('scheduled-routine', $event->intent);
        $this->assertTrue($event->payload['cron']);

        Bus::assertDispatched(RunCoordinatorJob::class, fn ($j) => $j->eventId === $event->id && $j->domain === 'sec-ir');
    }

    public function test_fire_unknown_domain_returns_null(): void
    {
        Bus::fake([RunCoordinatorJob::class]);
        $this->assertNull($this->app->make(CronTrigger::class)->fire('does-not-exist'));
        $this->assertSame(0, PaiEvent::count());
    }

    public function test_parse_cron_entry(): void
    {
        [$expr, $desc] = CronTrigger::parse('0 8 * * 2-5: 每日威脅情報彙整');
        $this->assertSame('0 8 * * 2-5', $expr);
        $this->assertSame('每日威脅情報彙整', $desc);
    }
}
