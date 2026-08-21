<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The picker listed titles alone, and every title is generated from the
 * opening question by the same prompt — so twenty conversations looked like
 * one entry repeated and users could not find the one they wanted. Each row
 * needs something the others do not have.
 */
class AiChatConversationListTest extends PackageTestCase
{
    private function seedConversation(array $attributes, array $messages, int $completedEdits = 0): AiConversation
    {
        $conversation = AiConversation::create($attributes);

        $lastMessageId = null;
        foreach ($messages as [$role, $content]) {
            $lastMessageId = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => $role,
                'content' => $content,
            ])->id;
        }

        for ($i = 0; $i < $completedEdits; $i++) {
            AiActionLog::create([
                'conversation_id' => $conversation->id,
                'message_id' => $lastMessageId,
                'user_id' => $conversation->user_id,
                'tool_name' => 'update_block',
                'parameters' => [],
                'status' => 'completed',
            ]);
        }

        return $conversation;
    }

    private function listConversations()
    {
        $user = $this->signIn();
        Gate::define('ai_chat_access', fn () => true);

        return [$user, fn () => $this->getJson(route('vela.admin.ai-chat.conversations'))];
    }

    public function test_each_row_carries_the_detail_that_tells_it_apart(): void
    {
        [$user, $request] = $this->listConversations();

        $this->seedConversation(
            ['user_id' => $user->id, 'title' => 'Pricing page'],
            [
                ['user', 'Build me a pricing page'],
                ['assistant', '## Done  I added **three** tiers to the pricing page.'],
                ['user', 'Make the middle one stand out'],
                ['assistant', 'The middle tier is now highlighted.'],
            ],
            2
        );

        $response = $request()->assertOk();
        $row = $response->json('conversations.0');

        $this->assertSame('Pricing page', $row['title']);
        // The last answer, not the opening question the title already carries.
        $this->assertSame('The middle tier is now highlighted.', $row['preview']);
        $this->assertSame(4, $row['message_count']);
        $this->assertSame(2, $row['edit_count']);
        $this->assertNotEmpty($row['updated_human']);
        $this->assertNotEmpty($row['updated_at']);
    }

    public function test_an_untitled_conversation_is_named_after_what_was_asked(): void
    {
        [$user, $request] = $this->listConversations();

        $this->seedConversation(
            ['user_id' => $user->id, 'title' => null],
            [['user', 'Why is the hero image not showing on the home page?']]
        );

        $row = $request()->assertOk()->json('conversations.0');

        $this->assertStringContainsString('Why is the hero image', $row['title']);
        $this->assertNotSame('Conversation #' . $row['id'], $row['title']);
    }

    public function test_a_conversation_with_no_messages_still_gets_a_usable_label(): void
    {
        [$user, $request] = $this->listConversations();

        $this->seedConversation(['user_id' => $user->id, 'title' => null], []);

        $row = $request()->assertOk()->json('conversations.0');

        $this->assertSame('Conversation #' . $row['id'], $row['title']);
        $this->assertSame('', $row['preview']);
        $this->assertSame(0, $row['message_count']);
        $this->assertSame(0, $row['edit_count']);
    }

    public function test_undone_and_failed_actions_are_not_counted_as_edits(): void
    {
        [$user, $request] = $this->listConversations();

        $conversation = $this->seedConversation(
            ['user_id' => $user->id, 'title' => 'Colours'],
            [['user', 'Make it blue'], ['assistant', 'Done.']],
            1
        );

        $messageId = AiMessage::where('conversation_id', $conversation->id)->value('id');

        AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $messageId,
            'user_id' => $user->id,
            'tool_name' => 'update_custom_css',
            'parameters' => [],
            'status' => 'completed',
            'undone_at' => now(),
        ]);

        AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $messageId,
            'user_id' => $user->id,
            'tool_name' => 'update_custom_css',
            'parameters' => [],
            'status' => 'failed',
        ]);

        $this->assertSame(1, $request()->assertOk()->json('conversations.0.edit_count'));
    }

    public function test_another_users_conversations_are_not_listed(): void
    {
        [$user, $request] = $this->listConversations();

        $this->seedConversation(['user_id' => $user->id, 'title' => 'Mine'], [['user', 'hi']]);
        $this->seedConversation(['user_id' => $user->id + 99, 'title' => 'Theirs'], [['user', 'hi']]);

        $titles = array_column($request()->assertOk()->json('conversations'), 'title');

        $this->assertSame(['Mine'], $titles);
    }
}
