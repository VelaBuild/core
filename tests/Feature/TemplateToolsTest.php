<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Tests\TestCase;

class TemplateToolsTest extends TestCase
{
    use DatabaseTransactions;

    private AiConversation $conversation;
    private AiMessage $message;
    private ChatToolExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->loginAsAdmin();

        $this->conversation = AiConversation::create([
            'user_id' => $user->id,
            'title'   => 'Template Tools Test Conversation',
        ]);

        $this->message = AiMessage::create([
            'conversation_id' => $this->conversation->id,
            'role'            => 'user',
            'content'         => 'Test message',
        ]);

        $this->executor = app(ChatToolExecutor::class);

        $this->defineGates();
    }

    private function defineGates(): void
    {
        $roles = Role::with('permissions')->get();
        $permissionsArray = [];

        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissionsArray[$permission->title][] = $role->id;
            }
        }

        foreach ($permissionsArray as $title => $roleIds) {
            Gate::define($title, function ($user) use ($roleIds) {
                return count(array_intersect($user->roles->pluck('id')->toArray(), $roleIds)) > 0;
            });
        }
    }

    public function test_new_template_tools_registered(): void
    {
        $registry = app(ChatToolRegistry::class);

        $this->assertTrue($registry->has('switch_template'));
        $this->assertTrue($registry->has('list_templates'));
        $this->assertTrue($registry->has('get_template_info'));
    }

    public function test_list_templates_returns_all_templates(): void
    {
        $user = auth('vela')->user();

        $result = $this->executor->execute(
            'list_templates',
            [],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['templates']);

        foreach ($result['templates'] as $template) {
            $this->assertArrayHasKey('name', $template);
            $this->assertArrayHasKey('label', $template);
            $this->assertArrayHasKey('description', $template);
            $this->assertArrayHasKey('category', $template);
            $this->assertArrayHasKey('active', $template);
        }
    }

    public function test_get_template_info_returns_details(): void
    {
        $user = auth('vela')->user();

        $result = $this->executor->execute(
            'get_template_info',
            ['template' => 'default'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('default', $result['template']['name']);
        $this->assertArrayHasKey('files', $result['template']);
    }

    public function test_get_template_info_errors_for_unknown_template(): void
    {
        $user = auth('vela')->user();

        $result = $this->executor->execute(
            'get_template_info',
            ['template' => 'nonexistent'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function test_switch_template_switches_template(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_config_manage']);
        $this->defineGates();

        $user = auth('vela')->user();

        $result = $this->executor->execute(
            'switch_template',
            ['template' => 'minimal'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('minimal', VelaConfig::where('key', 'active_template')->value('value'));
    }

    public function test_switch_template_rejects_invalid_template(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_config_manage']);
        $this->defineGates();

        $user = auth('vela')->user();

        $result = $this->executor->execute(
            'switch_template',
            ['template' => 'nonexistent'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function test_switch_template_undo_restores_previous(): void
    {
        Permission::firstOrCreate(['title' => 'ai_chat_config_manage']);
        $this->defineGates();

        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => 'default']);

        $user = auth('vela')->user();

        $result = $this->executor->execute(
            'switch_template',
            ['template' => 'minimal'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('minimal', VelaConfig::where('key', 'active_template')->value('value'));

        $actionLog = AiActionLog::where('tool_name', 'switch_template')->latest()->first();
        $this->assertNotNull($actionLog);

        $this->executor->undoAction($actionLog);

        $this->assertEquals('default', VelaConfig::where('key', 'active_template')->value('value'));
    }
}
