<?php

namespace Tests\Unit;

use App\Pai\Memory\LocalHashEmbeddings;
use PHPUnit\Framework\TestCase;

class LocalHashEmbeddingsTest extends TestCase
{
    private function dot(array $a, array $b): float
    {
        $s = 0.0;
        for ($i = 0; $i < count($a); $i++) {
            $s += $a[$i] * $b[$i];
        }

        return $s;
    }

    public function test_deterministic_and_normalized(): void
    {
        $e = new LocalHashEmbeddings(256);
        $v1 = $e->embed('勒索軟體攻擊 host-7');
        $v2 = $e->embed('勒索軟體攻擊 host-7');

        $this->assertSame($v1, $v2);                  // 確定性
        $this->assertCount(256, $v1);
        $this->assertEqualsWithDelta(1.0, sqrt($this->dot($v1, $v1)), 0.001); // L2 正規化
    }

    public function test_similar_text_scores_higher_than_dissimilar(): void
    {
        $e = new LocalHashEmbeddings(256);
        $q = $e->embed('主機 host-7 中了勒索病毒');
        $similar = $e->embed('勒索軟體 攻擊 host-7 主機');
        $different = $e->embed('CI 測試失敗 pytest 修正 add 函數');

        $this->assertGreaterThan($this->dot($q, $different), $this->dot($q, $similar));
    }
}
