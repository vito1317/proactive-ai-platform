<?php

namespace Tests\Feature;

use App\Pai\Governance\ActionFeedback;
use App\Pai\Governance\ProactivityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GovernanceTest extends TestCase
{
    use RefreshDatabase;

    private ProactivityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ProactivityPolicy;
    }

    public function test_default_config_passes_requested_level_through(): void
    {
        // 預設設定刻意寬鬆：不改變既有 copilot/supervisor/autopilot 行為
        $g = $this->policy->gate('sec-ir', 'contain-host', 0.7, 0.8, ProactivityPolicy::ASK);
        $this->assertSame(ProactivityPolicy::ASK, $g['level']);

        $g = $this->policy->gate('sec-ir', 'tag-asset', 0.9, 0.5, ProactivityPolicy::ACT);
        $this->assertSame(ProactivityPolicy::ACT, $g['level']);
    }

    public function test_low_confidence_demotes_to_observe(): void
    {
        $g = $this->policy->gate('sec-ir', 'contain-host', 0.2, 0.9, ProactivityPolicy::ASK);
        $this->assertSame(ProactivityPolicy::OBSERVE, $g['level']);
    }

    public function test_act_requires_higher_confidence(): void
    {
        // 信心 0.7 < act_confidence 0.85 → 自動執行降為請求核准
        $g = $this->policy->gate('sec-ir', 'tag-asset', 0.7, 0.5, ProactivityPolicy::ACT);
        $this->assertSame(ProactivityPolicy::ASK, $g['level']);
    }

    public function test_action_max_level_caps_grant(): void
    {
        config(['pai.governance.action_max_levels' => ['contain-host' => ProactivityPolicy::SUGGEST]]);

        $g = $this->policy->gate('sec-ir', 'contain-host', 0.95, 0.9, ProactivityPolicy::ACT);
        $this->assertSame(ProactivityPolicy::SUGGEST, $g['level']);
    }

    public function test_repeated_declines_demote_action(): void
    {
        foreach (range(1, 3) as $_) {
            $this->policy->recordFeedback('sec-ir', 'contain-host', positive: false);
        }

        $g = $this->policy->gate('sec-ir', 'contain-host', 0.7, 0.8, ProactivityPolicy::ASK);
        $this->assertSame(ProactivityPolicy::SUGGEST, $g['level']);
        $this->assertStringContainsString('駁回', $g['reason']);
    }

    public function test_approval_resets_decline_count(): void
    {
        foreach (range(1, 3) as $_) {
            $this->policy->recordFeedback('sec-ir', 'contain-host', positive: false);
        }
        $this->policy->recordFeedback('sec-ir', 'contain-host', positive: true);

        $this->assertSame(0, ActionFeedback::recentDeclines('sec-ir', 'contain-host', 7));
        $g = $this->policy->gate('sec-ir', 'contain-host', 0.7, 0.8, ProactivityPolicy::ASK);
        $this->assertSame(ProactivityPolicy::ASK, $g['level']);
    }

    public function test_quiet_hours_block_interruption_unless_urgent(): void
    {
        $hour = (int) now()->format('G');
        config(['pai.governance.quiet_hours' => $hour.'-'.(($hour + 1) % 24)]); // 現在就是安靜時段

        $this->assertFalse($this->policy->allowInterruption(0.5, 0.9)['allowed']);
        $this->assertTrue($this->policy->allowInterruption(0.95, 0.9)['allowed']); // 緊急可突破
    }

    public function test_interruption_cost_formula(): void
    {
        config(['pai.governance.interruption_cost' => 0.5]);

        // urgency × confidence = 0.3×0.9 = 0.27 ≤ 0.5 → 擋下
        $this->assertFalse($this->policy->allowInterruption(0.3, 0.9)['allowed']);
        // 0.95×0.9 = 0.855 > 0.5 → 放行
        $this->assertTrue($this->policy->allowInterruption(0.95, 0.9)['allowed']);
    }

    public function test_hourly_interruption_budget(): void
    {
        config(['pai.governance.max_interruptions_per_hour' => 2]);
        Cache::flush();

        $this->assertTrue($this->policy->allowInterruption(0.8, 0.9)['allowed']);
        $this->assertTrue($this->policy->allowInterruption(0.8, 0.9)['allowed']);
        $this->assertFalse($this->policy->allowInterruption(0.8, 0.9)['allowed']); // 第 3 次達上限
    }

    public function test_disabled_governance_is_passthrough(): void
    {
        config(['pai.governance.enabled' => false, 'pai.governance.interruption_cost' => 0.99]);

        $g = $this->policy->gate('sec-ir', 'contain-host', 0.01, 0.1, ProactivityPolicy::ACT);
        $this->assertSame(ProactivityPolicy::ACT, $g['level']);
        $this->assertTrue($this->policy->allowInterruption(0.1, 0.1)['allowed']);
    }
}
