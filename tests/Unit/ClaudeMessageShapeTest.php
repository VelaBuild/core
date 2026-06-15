<?php

namespace VelaBuild\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use VelaBuild\Core\Services\ClaudeTextService;

/**
 * Guards the Anthropic request shape that callers (ProcessAiChatMessageJob)
 * feed in OpenAI form. Every one of these assertions corresponds to a real
 * 400 we shipped to production: role "tool" not allowed, system-as-message,
 * duplicate tool names, and unpaired tool_use/tool_result blocks. The history
 * arrives corrupted (interrupted turns, dropped orphans, window truncation) —
 * the translator must always emit a structurally valid sequence.
 */
class ClaudeMessageShapeTest extends TestCase
{
    private function toAnthropic(array $messages): array
    {
        $svc = (new ReflectionClass(ClaudeTextService::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(ClaudeTextService::class, 'toAnthropicMessages');
        $m->setAccessible(true);
        return $m->invoke($svc, $messages);
    }

    /** Assert a message list obeys Anthropic's structural rules. */
    private function assertValidAnthropic(array $msgs): void
    {
        $this->assertNotEmpty($msgs, 'message list must not be empty');
        $this->assertSame('user', $msgs[0]['role'] ?? null, 'first message must be a user turn');

        $n = count($msgs);
        for ($i = 0; $i < $n; $i++) {
            $msg = $msgs[$i];
            $c = $msg['content'] ?? null;
            $this->assertFalse(is_array($c) && count($c) === 0, "msg $i has empty content array");
            $this->assertFalse(is_string($c) && $c === '', "msg $i has empty string content");
            if ($i > 0) {
                $this->assertNotSame($msgs[$i - 1]['role'] ?? null, $msg['role'] ?? null, "msgs " . ($i - 1) . "/$i are consecutive same-role");
            }
            if (!is_array($c)) {
                continue;
            }
            foreach ($c as $b) {
                if (($b['type'] ?? '') === 'tool_use') {
                    $next = $msgs[$i + 1] ?? null;
                    $resultIds = $next && is_array($next['content'] ?? null)
                        ? array_map(fn ($x) => $x['tool_use_id'] ?? null, array_filter($next['content'], fn ($x) => ($x['type'] ?? '') === 'tool_result'))
                        : [];
                    $this->assertTrue(($next['role'] ?? null) === 'user' && in_array($b['id'], $resultIds, true), "tool_use {$b['id']} (msg $i) lacks a matching tool_result in the next turn");
                }
                if (($b['type'] ?? '') === 'tool_result') {
                    $prev = $msgs[$i - 1] ?? null;
                    $useIds = $prev && is_array($prev['content'] ?? null)
                        ? array_map(fn ($x) => $x['id'] ?? null, array_filter($prev['content'], fn ($x) => ($x['type'] ?? '') === 'tool_use'))
                        : [];
                    $this->assertTrue(($prev['role'] ?? null) === 'assistant' && in_array($b['tool_use_id'], $useIds, true), "tool_result {$b['tool_use_id']} (msg $i) lacks a matching tool_use in the previous turn");
                }
            }
        }
    }

    public function test_orphan_tool_use_is_dropped(): void
    {
        // The production case: the result was already dropped upstream, leaving
        // an assistant tool_use with nothing to pair against.
        $out = $this->toAnthropic([
            ['role' => 'user', 'content' => 'build me a page'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'toolu_01FMhd', 'type' => 'function', 'function' => ['name' => 'create_page', 'arguments' => '{}']],
            ]],
            ['role' => 'assistant', 'content' => 'The AI provider returned an error mid-request.'],
            ['role' => 'user', 'content' => 'I need Fable back!'],
        ]);
        $this->assertValidAnthropic($out);
    }

    public function test_healthy_tool_turn_is_preserved(): void
    {
        $out = $this->toAnthropic([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'get_site_info', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"name":"Fable"}'],
            ['role' => 'assistant', 'content' => 'Your site is Fable.'],
        ]);
        $this->assertValidAnthropic($out);
        // The tool round must survive intact.
        $this->assertSame('tool_use', $out[1]['content'][0]['type']);
        $this->assertSame('tool_result', $out[2]['content'][0]['type']);
    }

    public function test_orphan_tool_result_is_dropped(): void
    {
        $out = $this->toAnthropic([
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'tool', 'tool_call_id' => 'toolu_9', 'content' => 'stale'],
            ['role' => 'user', 'content' => 'still here?'],
        ]);
        $this->assertValidAnthropic($out);
    }

    public function test_partial_multi_tool_call_keeps_only_resolved(): void
    {
        $out = $this->toAnthropic([
            ['role' => 'user', 'content' => 'do two things'],
            ['role' => 'assistant', 'content' => 'sure', 'tool_calls' => [
                ['id' => 'toolu_a', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{"k":1}']],
                ['id' => 'toolu_b', 'type' => 'function', 'function' => ['name' => 'y', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_a', 'content' => 'okA'],
            // toolu_b's result was never saved
            ['role' => 'assistant', 'content' => 'done'],
        ]);
        $this->assertValidAnthropic($out);
    }

    public function test_leading_assistant_is_trimmed_to_user(): void
    {
        $out = $this->toAnthropic([
            ['role' => 'assistant', 'content' => 'leftover'],
            ['role' => 'user', 'content' => 'go'],
        ]);
        $this->assertValidAnthropic($out);
    }
}
