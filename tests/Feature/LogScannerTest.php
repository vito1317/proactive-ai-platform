<?php

namespace Tests\Feature;

use App\Pai\Cognition\RunCoordinatorJob;
use App\Pai\Perception\LogScanner;
use App\Pai\Perception\PaiEvent;
use App\Pai\Perception\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class LogScannerTest extends TestCase
{
    use RefreshDatabase;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RunCoordinatorJob::class]); // 路由會跑，但不觸發 LLM
        $this->log = storage_path('app/test-log-'.uniqid().'.log');
        config(['pai.logops.sources' => [$this->log]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function write(string $content): void
    {
        file_put_contents($this->log, $content);
    }

    public function test_detects_error_and_routes_to_log_ops(): void
    {
        $this->write("INFO: ok\nERROR: Connection refused to redis\nINFO: ok2\n");

        $this->assertSame(1, $this->app->make(LogScanner::class)->scan());

        $event = PaiEvent::where('topic', 'log.error')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('log-ops', $event->domain);       // 路由正確（IngestEventJob sync 跑過）
        $this->assertSame('log-error', $event->intent);
        $this->assertStringContainsString('Connection refused', $event->payload['excerpt']);
    }

    public function test_offset_prevents_reprocessing(): void
    {
        $this->write("ERROR: first\n");
        $this->assertSame(1, $this->app->make(LogScanner::class)->scan());
        $this->assertSame(0, $this->app->make(LogScanner::class)->scan()); // 無新增

        // 追加新錯誤 → 只處理新行
        file_put_contents($this->log, "INFO: noise\nCRITICAL: Fatal out of memory\n", FILE_APPEND);
        $this->assertSame(1, $this->app->make(LogScanner::class)->scan());

        $latest = PaiEvent::where('topic', 'log.error')->latest('id')->first();
        $this->assertSame(Severity::Critical, $latest->severity); // CRITICAL → critical
    }

    public function test_no_event_when_no_errors(): void
    {
        $this->write("INFO: all good\nDEBUG: trace\n");
        $this->assertSame(0, $this->app->make(LogScanner::class)->scan());
        $this->assertSame(0, PaiEvent::where('topic', 'log.error')->count());
    }

    public function test_multiline_stack_trace_is_single_event(): void
    {
        $this->write("ERROR: Uncaught Exception: boom\nStack trace:\n#0 /app/x.php(10)\n#1 {main}\n");
        $this->assertSame(1, $this->app->make(LogScanner::class)->scan()); // 不因 Exception+Stack trace 重複觸發
    }
}
