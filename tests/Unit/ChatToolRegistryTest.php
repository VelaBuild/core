<?php

namespace VelaBuild\Core\Tests\Unit;

use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;

class ChatToolRegistryTest extends TestCase
{
    use DatabaseTransactions;

    private ChatToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ChatToolRegistry();
    }

    public function test_returns_all_tool_schemas(): void
    {
        $tools = $this->registry->all();

        $this->assertGreaterThanOrEqual(15, count($tools));

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('parameters', $tool);
            $this->assertArrayHasKey('write', $tool);
        }
    }

    public function test_rejects_unregistered_tool_names(): void
    {
        $this->assertFalse($this->registry->has('nonexistent_tool'));
        $this->assertFalse($this->registry->has(''));
        $this->assertFalse($this->registry->has('hack_site'));
    }

    public function test_filters_tools_by_user_permissions(): void
    {
        // Use Gate::define to control abilities without needing a real DB user.
        // Gate::forUser() passes any object to the closure, so we can use a simple stdClass.
        $user = new \stdClass();
        $user->id = 999;

        // Define gates: deny config_edit, allow page_access, leave others undefined (default deny)
        Gate::define('config_edit', fn($u) => false);
        Gate::define('page_access', fn($u) => true);
        Gate::define('article_access', fn($u) => false);
        Gate::define('category_access', fn($u) => false);
        Gate::define('page_create', fn($u) => false);
        Gate::define('page_edit', fn($u) => false);
        Gate::define('article_create', fn($u) => false);
        Gate::define('article_edit', fn($u) => false);
        Gate::define('category_create', fn($u) => false);
        Gate::define('ai_chat_template_edit', fn($u) => false);

        $tools = $this->registry->forUser($user);
        $toolNames = array_column($tools, 'name');

        // get_site_config has no gate, should always be included
        $this->assertContains('get_site_config', $toolNames);

        // list_pages requires page_access which we allowed
        $this->assertContains('list_pages', $toolNames);

        // update_site_config requires config_edit which we denied
        $this->assertNotContains('update_site_config', $toolNames);

        // update_template_colors also requires config_edit
        $this->assertNotContains('update_template_colors', $toolNames);
    }

    public function test_converts_to_openai_format(): void
    {
        $tools = array_slice($this->registry->all(), 0, 3);
        $openAiTools = $this->registry->toOpenAiFormat($tools);

        $this->assertCount(3, $openAiTools);

        foreach ($openAiTools as $tool) {
            $this->assertArrayHasKey('type', $tool);
            $this->assertEquals('function', $tool['type']);
            $this->assertArrayHasKey('function', $tool);
            $this->assertArrayHasKey('name', $tool['function']);
            $this->assertArrayHasKey('description', $tool['function']);
            $this->assertArrayHasKey('parameters', $tool['function']);
        }
    }

    public function test_converts_to_anthropic_format(): void
    {
        $tools = array_slice($this->registry->all(), 0, 3);
        $anthropicTools = $this->registry->toAnthropicFormat($tools);

        $this->assertCount(3, $anthropicTools);

        foreach ($anthropicTools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('input_schema', $tool);
        }
    }
}
