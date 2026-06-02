<?php

namespace Tests\Feature;

use App\Pai\Cognition\ClassifyCommandJob;
use App\Pai\Perception\PaiEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NlCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(\App\Models\User::create(['name' => 'T', 'email' => 't@pai.test', 'password' => bcrypt('x')]));
    }

    public function test_ask_creates_event_and_dispatches_classifier(): void
    {
        Bus::fake();

        $res = $this->post('/console/ask', ['message' => '有一台主機中了勒索病毒，幫我處理']);

        $res->assertRedirect();
        $this->assertDatabaseHas('pai_events', [
            'source' => 'console',
            'topic' => 'console.request',
            'status' => 'received',
        ]);
        $event = PaiEvent::latest('id')->first();
        $this->assertSame('有一台主機中了勒索病毒，幫我處理', $event->payload['message']);

        Bus::assertDispatched(ClassifyCommandJob::class, fn ($job) => $job->eventId === $event->id);
    }

    public function test_ask_requires_message(): void
    {
        $this->post('/console/ask', ['message' => ''])->assertSessionHasErrors('message');
    }
}
