<?php

namespace Tests\Feature;

use App\Pai\Memory\MemoryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_recall_returns_most_similar_within_namespace(): void
    {
        $mem = $this->app->make(MemoryStore::class);
        $mem->remember('sec-ir', '主機 host-7 偵測到勒索軟體，已隔離端點', 'incident');
        $mem->remember('sec-ir', '釣魚郵件事件，已封鎖寄件者網域', 'incident');

        $hits = $mem->recall('sec-ir', '有主機中了勒索病毒要怎麼處理', 2);

        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('勒索軟體', $hits[0]['content']);   // 勒索 > 釣魚
        $this->assertSame('incident', $hits[0]['kind']);
    }

    public function test_namespace_isolation(): void
    {
        $mem = $this->app->make(MemoryStore::class);
        $mem->remember('sec-ir', '勒索軟體事件處置', 'incident');
        $mem->remember('dev-auto', 'CI 失敗，修正 calculator.add', 'dev-task');

        $hits = $mem->recall('dev-auto', '勒索軟體', 5);

        // 只會回 dev-auto 命名空間的記憶，不會撈到 sec-ir
        foreach ($hits as $h) {
            $this->assertSame('dev-task', $h['kind']);
        }
        $this->assertCount(1, $hits);
    }
}
