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

        // 相關查詢只會回 dev-auto 命名空間的記憶，不會撈到 sec-ir
        $hits = $mem->recall('dev-auto', 'CI 失敗要修正 calculator', 5);
        foreach ($hits as $h) {
            $this->assertSame('dev-task', $h['kind']);
        }
        $this->assertCount(1, $hits);

        // 不相關查詢也絕不會跨命名空間撈到 sec-ir（min_score 門檻下可為空）
        foreach ($mem->recall('dev-auto', '勒索軟體', 5) as $h) {
            $this->assertSame('dev-task', $h['kind']);
        }
    }

    public function test_remember_dedupes_identical_content(): void
    {
        $mem = $this->app->make(MemoryStore::class);
        $mem->remember('sec-ir', '主機 host-7 偵測到勒索軟體，已隔離端點', 'incident');
        $mem->remember('sec-ir', '主機 host-7 偵測到勒索軟體，已隔離端點', 'incident');

        $this->assertSame(1, \App\Pai\Memory\Memory::where('namespace', 'sec-ir')->count());
    }

    public function test_search_covers_memories_beyond_recent_500(): void
    {
        $mem = $this->app->make(MemoryStore::class);
        // 最舊的一筆是唯一相關的；之後塞 520 筆雜訊 → 舊版只掃最近 500 筆會永遠搜不到它
        $mem->remember('sec-ir', '主機 host-7 偵測到勒索軟體，已隔離端點', 'incident');
        for ($i = 0; $i < 520; $i++) {
            $mem->remember('sec-ir', "例行健康檢查第 {$i} 次，一切正常", 'note');
        }

        $hits = $mem->recall('sec-ir', '勒索軟體 隔離', 3);

        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('勒索軟體', $hits[0]['content']);
    }
}
