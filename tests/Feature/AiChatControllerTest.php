<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;

class AiChatControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_requires_authentication(): void
    {
        $response = $this->postJson(route('vela.admin.ai-chat.message'), [
            'message' => 'Hello',
        ]);

        // Unauthenticated JSON request returns 401 or redirect; for web routes it redirects
        $this->assertTrue(
            $response->status() === 302 || $response->status() === 401,
            'Expected 302 or 401 for unauthenticated request, got ' . $response->status()
        );
    }

    public function test_requires_ai_chat_access_permission(): void
    {
        // loginAsUser gives User role which does NOT have ai_chat_access by default
        // (it's excluded in VelaRolesSeeder via $userExcluded)
        $this->loginAsUser();

        $response = $this->postJson(route('vela.admin.ai-chat.message'), [
            'message' => 'Hello',
        ]);

        $response->assertStatus(403);
    }

    public function test_creates_conversation_on_first_message(): void
    {
        Bus::fake();

        Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $admin = $this->loginAsAdmin();

        // Set queue to async so Bus::fake intercepts the job
        config()->set('queue.default', 'database');

        $response = $this->postJson(route('vela.admin.ai-chat.message'), [
            'message' => 'Hello there',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('vela_ai_conversations', [
            'user_id' => $admin->id,
        ]);
    }

    public function test_returns_conversation_id_and_processing_status(): void
    {
        Bus::fake();

        Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $this->loginAsAdmin();

        config()->set('queue.default', 'database');

        $response = $this->postJson(route('vela.admin.ai-chat.message'), [
            'message' => 'What can you do?',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'conversation_id', 'status']);
        $this->assertIsInt($response->json('conversation_id'));
        $this->assertNotNull($response->json('status'));
    }

    public function test_poll_returns_new_messages(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $admin = $this->loginAsAdmin();

        $conversation = AiConversation::create([
            'user_id' => $admin->id,
            'title' => 'Test conversation',
        ]);

        $msg1 = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'First message',
        ]);

        $msg2 = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'First reply',
        ]);

        $response = $this->getJson(
            route('vela.admin.ai-chat.poll', $conversation->id) . '?after=' . $msg1->id
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $messages = $response->json('messages');
        $this->assertCount(1, $messages);
        $this->assertEquals($msg2->id, $messages[0]['id']);
    }

    public function test_poll_rejects_other_users_conversation(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $admin = $this->loginAsAdmin();

        // Create conversation belonging to admin
        $conversation = AiConversation::create([
            'user_id' => $admin->id,
            'title' => 'Admin conversation',
        ]);

        // Create and login as a different user
        $otherUser = VelaUser::factory()->create();
        $userRole = Role::firstOrCreate(['id' => 2], ['title' => 'User']);
        $otherUser->roles()->attach($userRole);

        // Assign ai_chat_access to the other user too so the Gate passes
        $permission = Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $userRole->permissions()->syncWithoutDetaching([$permission->id]);

        $this->actingAs($otherUser, 'vela');

        $response = $this->getJson(
            route('vela.admin.ai-chat.poll', $conversation->id) . '?after=0'
        );

        $response->assertStatus(403);
    }

    public function test_undo_restores_previous_config_state(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $admin = $this->loginAsAdmin();

        // Set up a VelaConfig entry
        $configKey = 'test_undo_key_' . uniqid();
        $originalValue = 'original_value';
        VelaConfig::create(['key' => $configKey, 'value' => $originalValue]);

        // Create conversation and message
        $conversation = AiConversation::create([
            'user_id' => $admin->id,
            'title' => 'Undo test',
        ]);
        $message = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'I updated the config',
        ]);

        // Simulate a config update by changing the value
        VelaConfig::where('key', $configKey)->update(['value' => 'new_value']);

        // Create action log with previous state
        $actionLog = AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'user_id' => $admin->id,
            'tool_name' => 'update_site_config',
            'parameters' => ['key' => $configKey, 'value' => 'new_value'],
            'previous_state' => ['key' => $configKey, 'value' => $originalValue, 'existed' => true],
            'result' => ['success' => true],
            'status' => 'completed',
        ]);

        $response = $this->postJson(route('vela.admin.ai-chat.undo', $actionLog->id));

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('vela_configs', [
            'key' => $configKey,
            'value' => $originalValue,
        ]);
    }

    public function test_undo_rejects_already_undone_actions(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $admin = $this->loginAsAdmin();

        $conversation = AiConversation::create([
            'user_id' => $admin->id,
            'title' => 'Already undone test',
        ]);
        $message = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Done',
        ]);

        $actionLog = AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'user_id' => $admin->id,
            'tool_name' => 'update_site_config',
            'parameters' => ['key' => 'some_key', 'value' => 'some_value'],
            'previous_state' => ['key' => 'some_key', 'value' => 'old', 'existed' => true],
            'result' => ['success' => true],
            'status' => 'completed',
            'undone_at' => now(),
        ]);

        $response = $this->postJson(route('vela.admin.ai-chat.undo', $actionLog->id));

        $response->assertStatus(400);
        $response->assertJson(['success' => false]);
    }

    public function test_rate_limit_enforced(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $admin = $this->loginAsAdmin();

        // Create a conversation to attach messages to
        $conversation = AiConversation::create([
            'user_id' => $admin->id,
            'title' => 'Rate limit test',
        ]);

        // Create 50 user messages in the last hour
        $limit = config('vela.ai.chat.rate_limit', 50);
        for ($i = 0; $i < $limit; $i++) {
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => "Message {$i}",
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ]);
        }

        // Send the 51st message
        $response = $this->postJson(route('vela.admin.ai-chat.message'), [
            'message' => 'This should be rate limited',
        ]);

        $response->assertStatus(429);
        $response->assertJson(['success' => false]);
    }

    public function test_conversations_lists_user_conversations(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_access']);
        $admin = $this->loginAsAdmin();

        AiConversation::create(['user_id' => $admin->id, 'title' => 'Conversation 1']);
        AiConversation::create(['user_id' => $admin->id, 'title' => 'Conversation 2']);
        AiConversation::create(['user_id' => $admin->id, 'title' => 'Conversation 3']);

        $response = $this->getJson(route('vela.admin.ai-chat.conversations'));

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $conversations = $response->json('conversations');
        $this->assertGreaterThanOrEqual(3, count($conversations));
    }
}
