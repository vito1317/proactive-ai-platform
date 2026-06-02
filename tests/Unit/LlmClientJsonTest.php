<?php

namespace Tests\Unit;

use App\Pai\Cognition\LlmClient;
use App\Pai\Cognition\LlmException;
use PHPUnit\Framework\TestCase;

class LlmClientJsonTest extends TestCase
{
    public function test_parses_plain_json(): void
    {
        $out = LlmClient::extractJson('{"action":"finish","ok":true}');
        $this->assertSame('finish', $out['action']);
        $this->assertTrue($out['ok']);
    }

    public function test_parses_code_fenced_json(): void
    {
        $raw = "Here you go:\n```json\n{\"thought\":\"hmm\",\"action\":\"note\"}\n```\nDone.";
        $out = LlmClient::extractJson($raw);
        $this->assertSame('note', $out['action']);
    }

    public function test_parses_json_with_surrounding_prose(): void
    {
        $raw = 'Sure! {"action":"propose_action","action_input":{"x":1}} hope that helps';
        $out = LlmClient::extractJson($raw);
        $this->assertSame('propose_action', $out['action']);
        $this->assertSame(1, $out['action_input']['x']);
    }

    public function test_throws_when_no_json(): void
    {
        $this->expectException(LlmException::class);
        LlmClient::extractJson('I cannot help with that.');
    }
}
