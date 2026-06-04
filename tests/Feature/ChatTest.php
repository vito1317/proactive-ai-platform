<?php

namespace Tests\Feature;

use App\Models\User;
use App\Pai\Chat\ChatResponder;
use App\Pai\Chat\Conversation;
use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Perception\PaiEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function respJson(array $obj): array
    {
        return ['choices' => [['message' => ['content' => json_encode($obj, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']], 'usage' => []];
    }

    private function text(string $t): array
    {
        return ['choices' => [['message' => ['content' => $t], 'finish_reason' => 'stop']], 'usage' => []];
    }

    public function test_chat_category_replies_conversationally(): void
    {
        // 無動作訊號 → 走快速路徑直接對話（不呼叫分類 LLM），只需回覆這一次
        Http::fakeSequence()
            ->push($this->text('你好！我可以幫你處理資安事件、修 bug、或新增監控領域。'));

        $conv = Conversation::create([]);
        $conv->addMessage('user', '你好，你能做什麼');

        $r = $this->app->make(ChatResponder::class)->respond($conv, '你好，你能做什麼');

        $this->assertSame('chat', $r['meta']['category']);
        $this->assertStringContainsString('資安事件', $r['reply']);
    }

    public function test_task_category_triggers_coordinator(): void
    {
        Bus::fake([RunCoordinatorJob::class]);
        Http::fakeSequence()
            ->push($this->respJson(['category' => 'task', 'reason' => '處理事件']))
            ->push($this->respJson(['domain' => 'sec-ir', 'topic' => 'siem.alert', 'severity' => 'high', 'rationale' => '勒索']));

        $conv = Conversation::create([]);
        $conv->addMessage('user', 'host-7 中勒索病毒幫我處理');

        $r = $this->app->make(ChatResponder::class)->respond($conv, 'host-7 中勒索病毒幫我處理');

        $this->assertSame('task', $r['meta']['category']);
        $this->assertStringContainsString('事件 #', $r['reply']);
        $this->assertSame('sec-ir', PaiEvent::where('source', 'chat')->latest('id')->first()->domain);
        Bus::assertDispatched(RunCoordinatorJob::class);
    }

    public function test_send_endpoint_persists_messages(): void
    {
        $this->actingAs(User::create(['name' => 'T', 'email' => 't@pai.test', 'password' => bcrypt('x')]));
        // 「嗨」無動作訊號 → 快速路徑直接對話，只回覆一次
        Http::fakeSequence()
            ->push($this->text('收到，我在這裡。'));

        $this->post('/chat/send', ['message' => '嗨'])->assertRedirect();

        $conv = Conversation::latest('id')->first();
        $this->assertCount(2, $conv->messages); // user + assistant
        $this->assertSame('收到，我在這裡。', $conv->messages[1]->content);
    }
}
