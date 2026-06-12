<?php

namespace Tests\Unit;

use App\Pai\Cognition\TokenEstimator;
use PHPUnit\Framework\TestCase;

class TokenEstimatorTest extends TestCase
{
    public function test_cjk_counts_per_char_and_ascii_per_four_chars(): void
    {
        $this->assertSame(0, TokenEstimator::estimate(''));
        $this->assertSame(4, TokenEstimator::estimate('你好世界'));          // 4 CJK
        $this->assertSame(3, TokenEstimator::estimate('hello world.'));      // 12 ASCII / 4
        $this->assertSame(5, TokenEstimator::estimate('你好 abcdefgh'));     // 2 CJK + ceil(9/4)=3
    }

    public function test_truncate_keeps_text_within_budget(): void
    {
        $long = str_repeat('這是一段很長的中文敘述，', 100);
        $cut = TokenEstimator::truncate($long, 50);

        $this->assertLessThanOrEqual(50 + 10, TokenEstimator::estimate($cut)); // 含省略標記的些微浮動
        $this->assertStringEndsWith('…（已截斷）', $cut);
        $this->assertSame('short', TokenEstimator::truncate('short', 50));     // 沒超過 → 原樣
    }

    public function test_estimate_messages_handles_multimodal_parts(): void
    {
        $messages = [
            ['role' => 'system', 'content' => '你是助理'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => '看看這張圖'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,xxxx']],
            ]],
        ];

        $est = TokenEstimator::estimateMessages($messages);
        $this->assertGreaterThan(1024, $est);   // 圖片固定成本有算進去
        $this->assertLessThan(1200, $est);
    }
}
