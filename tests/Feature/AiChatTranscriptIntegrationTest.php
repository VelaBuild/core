<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Jobs\ProcessAiChatMessageJob;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Runs the chat job against a recording provider so the transcript it actually
 * builds is asserted, rather than a reconstruction of the query. Both defects
 * these cover made every later message in a conversation fail at the provider,
 * which reads to the user as "the chat is broken for good".
 */
class AiChatTranscriptIntegrationTest extends PackageTestCase
{
    private RecordingTextProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new RecordingTextProvider();

        $manager = $this->createStub(AiProviderManager::class);
        $manager->method('resolveTextProvider')->willReturn($this->provider);
        $manager->method('hasTextProvider')->willReturn(true);
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function runJobFor(AiConversation $conversation, int $triggerId, string $body): void
    {
        (new ProcessAiChatMessageJob(
            $conversation->id,
            $conversation->user_id,
            [],
            $triggerId,
            $body,
            null
        ))->handle();
    }

    public function test_the_provider_receives_the_conversation_oldest_first(): void
    {
        $user = $this->signIn();
        $conversation = AiConversation::create(['user_id' => $user->id, 'title' => 'order']);

        foreach (['first question', 'second question'] as $body) {
            AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $body]);
            AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'ok']);
        }

        $trigger = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => 'third question',
        ]);

        $this->runJobFor($conversation, $trigger->id, 'third question');

        $userTurns = array_values(array_filter(
            $this->provider->lastMessages,
            fn ($m) => ($m['role'] ?? '') === 'user'
        ));

        $this->assertSame(
            ['first question', 'second question', 'third question'],
            array_column($userTurns, 'content'),
            'the transcript reached the provider out of order'
        );
    }

    public function test_a_conversation_left_mid_tool_call_still_reaches_the_provider(): void
    {
        // Exactly what a crashed or timed-out job leaves behind: the assistant
        // turn announcing a tool call, with no result row after it.
        $user = $this->signIn();
        $conversation = AiConversation::create(['user_id' => $user->id, 'title' => 'dangling']);

        AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'add a photo']);
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => '',
            'tool_calls'      => [['id' => 'call_dead', 'name' => 'generate_image', 'arguments' => []]],
        ]);

        $trigger = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => 'are you there?',
        ]);

        $this->runJobFor($conversation, $trigger->id, 'are you there?');

        foreach ($this->provider->lastMessages as $message) {
            if (($message['role'] ?? '') !== 'assistant' || empty($message['tool_calls'])) {
                continue;
            }
            $this->fail('an unanswered tool_call was sent to the provider — it would reject the whole request');
        }

        $this->assertSame(
            'Done.',
            AiMessage::where('conversation_id', $conversation->id)->orderByDesc('id')->first()->content,
            'the user got an error instead of a reply'
        );
    }
}

/**
 * Captures the message array the job hands over, and answers with plain text so
 * the job finishes in one round.
 */
class RecordingTextProvider implements AiTextProvider
{
    public array $lastMessages = [];

    public function generateText(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?string
    {
        return 'Done.';
    }

    public function chat(array $messages, array $tools = [], int $maxTokens = 4096): ?array
    {
        $this->lastMessages = $messages;

        return ['content' => 'Done.', 'tool_calls' => null, 'usage' => ['input' => 0, 'output' => 0]];
    }

    public function supportsVision(): bool
    {
        return false;
    }
}
