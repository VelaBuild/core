<?php

namespace VelaBuild\Core\Tests\Unit;

use ReflectionMethod;
use VelaBuild\Core\Jobs\ProcessAiChatMessageJob;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The transcript handed to the provider must be ordered oldest-first and must
 * never contain a tool_call without its result (or a result without its call).
 * Either defect makes the provider reject every later message in that
 * conversation, so the chat appears permanently broken to the user.
 */
class AiChatTranscriptOrderTest extends PackageTestCase
{
    private function dropOrphans(array $messages): array
    {
        $job = new ProcessAiChatMessageJob(1, 1, [], null, null, null);
        $method = new ReflectionMethod($job, 'dropOrphanToolMessages');
        $method->setAccessible(true);

        return $method->invoke($job, $messages);
    }

    private function assistantCalling(string $id, string $tool = 'create_page'): array
    {
        return [
            'role'       => 'assistant',
            'content'    => null,
            'tool_calls' => [[
                'id'       => $id,
                'type'     => 'function',
                'function' => ['name' => $tool, 'arguments' => '{}'],
            ]],
        ];
    }

    public function test_a_completed_tool_round_is_left_untouched(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'prompt'],
            ['role' => 'user', 'content' => 'make a page'],
            $this->assistantCalling('call_a'),
            ['role' => 'tool', 'content' => '{"success":true}', 'tool_call_id' => 'call_a'],
            ['role' => 'assistant', 'content' => 'Done.'],
        ];

        $this->assertSame($messages, $this->dropOrphans($messages));
    }

    public function test_a_tool_call_that_never_got_a_result_is_dropped(): void
    {
        // What a job killed mid tool loop leaves behind — the assistant turn
        // announcing the call, with no matching result after it.
        $result = $this->dropOrphans([
            ['role' => 'system', 'content' => 'prompt'],
            ['role' => 'user', 'content' => 'add a photo'],
            $this->assistantCalling('call_dead', 'generate_image'),
            ['role' => 'user', 'content' => 'are you there?'],
        ]);

        $roles = array_column($result, 'role');
        $this->assertSame(['system', 'user', 'user'], $roles);
        foreach ($result as $message) {
            $this->assertArrayNotHasKey('tool_calls', $message);
        }
    }

    public function test_only_the_unanswered_call_is_dropped_from_a_parallel_round(): void
    {
        $assistant = $this->assistantCalling('call_ok');
        $assistant['tool_calls'][] = [
            'id'       => 'call_dead',
            'type'     => 'function',
            'function' => ['name' => 'add_block', 'arguments' => '{}'],
        ];

        $result = $this->dropOrphans([
            ['role' => 'user', 'content' => 'build it'],
            $assistant,
            ['role' => 'tool', 'content' => '{"success":true}', 'tool_call_id' => 'call_ok'],
        ]);

        $this->assertCount(3, $result);
        $this->assertCount(1, $result[1]['tool_calls']);
        $this->assertSame('call_ok', $result[1]['tool_calls'][0]['id']);
    }

    public function test_an_assistant_turn_keeping_its_text_survives_losing_its_call(): void
    {
        $assistant = $this->assistantCalling('call_dead');
        $assistant['content'] = 'Let me look that up.';

        $result = $this->dropOrphans([
            ['role' => 'user', 'content' => 'hi'],
            $assistant,
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('Let me look that up.', $result[1]['content']);
        $this->assertArrayNotHasKey('tool_calls', $result[1]);
    }

    public function test_a_tool_result_without_its_call_is_dropped(): void
    {
        $result = $this->dropOrphans([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'tool', 'content' => '{"success":true}', 'tool_call_id' => 'call_gone'],
            ['role' => 'assistant', 'content' => 'Done.'],
        ]);

        $this->assertSame(['user', 'assistant'], array_column($result, 'role'));
    }

    public function test_conversation_history_is_loaded_oldest_first(): void
    {
        // The messages() relation sorts by created_at then id, so appending
        // another orderBy('id') is a dead clause: the query still returns
        // oldest-first and the job's ->reverse() would invert the transcript.
        // reorder() is what actually makes "take the newest N" work.
        $conversation = \VelaBuild\Core\Models\AiConversation::create([
            'user_id' => $this->signIn()->id,
            'title'   => 'ordering',
        ]);

        foreach (['first', 'second', 'third'] as $body) {
            \VelaBuild\Core\Models\AiMessage::create([
                'conversation_id' => $conversation->id,
                'role'            => 'user',
                'content'         => $body,
            ]);
        }

        $loaded = $conversation->messages()
            ->reorder('id', 'desc')
            ->take(50)
            ->get()
            ->reverse()
            ->values();

        $this->assertSame(
            ['first', 'second', 'third'],
            $loaded->pluck('content')->all()
        );
    }
}
