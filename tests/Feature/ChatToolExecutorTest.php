<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Services\AiChat\Tools\UpdateSiteConfigTool;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;

class ChatToolExecutorTest extends TestCase
{
    use DatabaseTransactions;

    private AiConversation $conversation;
    private AiMessage $message;
    private ChatToolExecutor $executor;

    private array $createdContentIds = [];
    private array $createdVelaConfigKeys = [];
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['title' => 'config_edit']);
        Permission::firstOrCreate(['title' => 'article_create']);
        Permission::firstOrCreate(['title' => 'ai_chat_template_edit']);

        $user = $this->loginAsAdmin();

        $this->conversation = AiConversation::create([
            'user_id' => $user->id,
            'title'   => 'Test Conversation',
        ]);

        $this->message = AiMessage::create([
            'conversation_id' => $this->conversation->id,
            'role'            => 'user',
            'content'         => 'Test message',
        ]);

        $this->executor = app(ChatToolExecutor::class);

        // Gates are only defined via VelaAuthGates middleware in HTTP requests.
        // When calling the executor directly in tests, we must define them manually.
        $this->defineGates();
    }

    /**
     * Register permission Gates as VelaAuthGates middleware would during HTTP requests.
     */
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

    protected function tearDown(): void
    {
        foreach ($this->createdVelaConfigKeys as $key) {
            VelaConfig::where('key', $key)->forceDelete();
        }

        foreach ($this->createdContentIds as $id) {
            Content::withTrashed()->where('id', $id)->forceDelete();
        }

        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_executes_whitelisted_tool(): void
    {
        $user = auth('vela')->user();

        $result = $this->executor->execute(
            'get_site_config',
            [],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    public function test_rejects_unwhitelisted_tool(): void
    {
        $user = auth('vela')->user();

        $result = $this->executor->execute(
            'nonexistent_tool',
            [],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unknown tool', $result['error']);
    }

    public function test_checks_gate_permission_before_execution(): void
    {
        $user = $this->loginAsUser();

        $result = $this->executor->execute(
            'update_site_config',
            ['key' => 'test_perm_key', 'value' => 'test_value'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Permission denied', $result['error']);
    }

    public function test_records_action_log_for_write_tools(): void
    {
        $user = auth('vela')->user();
        $testKey = 'test_action_log_key_' . uniqid();
        $this->createdVelaConfigKeys[] = $testKey;

        $result = $this->executor->execute(
            'update_site_config',
            ['key' => $testKey, 'value' => 'test_value'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertTrue($result['success'] ?? false);

        $this->assertDatabaseHas('vela_ai_action_logs', [
            'conversation_id' => $this->conversation->id,
            'tool_name'       => 'update_site_config',
            'status'          => 'completed',
        ]);
    }

    public function test_records_previous_state_for_undo(): void
    {
        $user = auth('vela')->user();
        $testKey = 'test_undo_key_' . uniqid();
        $this->createdVelaConfigKeys[] = $testKey;

        VelaConfig::create(['key' => $testKey, 'value' => 'old_value']);

        $result = $this->executor->execute(
            'update_site_config',
            ['key' => $testKey, 'value' => 'new_value'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertTrue($result['success'] ?? false);

        $actionLog = AiActionLog::where('conversation_id', $this->conversation->id)
            ->where('tool_name', 'update_site_config')
            ->where('status', 'completed')
            ->latest()
            ->first();

        $this->assertNotNull($actionLog);
        $this->assertNotNull($actionLog->previous_state);
        $this->assertEquals($testKey, $actionLog->previous_state['key']);
        $this->assertEquals('old_value', $actionLog->previous_state['value']);
        $this->assertTrue($actionLog->previous_state['existed']);
    }

    public function test_undo_restores_config_value(): void
    {
        $user = auth('vela')->user();
        $testKey = 'test_undo_restore_key_' . uniqid();
        $this->createdVelaConfigKeys[] = $testKey;

        VelaConfig::create(['key' => $testKey, 'value' => 'old_value']);

        $this->executor->execute(
            'update_site_config',
            ['key' => $testKey, 'value' => 'new_value'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $actionLog = AiActionLog::where('conversation_id', $this->conversation->id)
            ->where('tool_name', 'update_site_config')
            ->where('status', 'completed')
            ->latest()
            ->first();

        $this->assertNotNull($actionLog);

        $this->executor->undoAction($actionLog);

        $restoredValue = VelaConfig::where('key', $testKey)->value('value');
        $this->assertEquals('old_value', $restoredValue);
        $this->assertNotNull($actionLog->fresh()->undone_at);
    }

    public function test_undo_soft_deletes_created_content(): void
    {
        $user = auth('vela')->user();

        if (!\DB::table('vela_users')->where('id', 1)->exists()) {
            \DB::table('vela_users')->insert([
                'id'         => 1,
                'name'       => 'Test Author',
                'email'      => 'test-author-executor@test.com',
                'password'   => \Hash::make('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $title = 'Test Article Undo ' . uniqid();

        $result = $this->executor->execute(
            'create_article',
            ['title' => $title, 'content' => 'Test content', 'status' => 'draft'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertTrue($result['success'] ?? false);
        $articleId = $result['article']['id'] ?? null;
        $this->assertNotNull($articleId);
        $this->createdContentIds[] = $articleId;

        $actionLog = AiActionLog::where('conversation_id', $this->conversation->id)
            ->where('tool_name', 'create_article')
            ->where('status', 'completed')
            ->latest()
            ->first();

        $this->assertNotNull($actionLog);

        $this->executor->undoAction($actionLog);

        $article = Content::withTrashed()->find($articleId);
        $this->assertNotNull($article);
        $this->assertTrue($article->trashed());
    }

    public function test_undo_restores_template_from_backup(): void
    {
        $user = auth('vela')->user();

        $tmpTemplateDir = sys_get_temp_dir() . '/vela-test-template-' . uniqid();
        mkdir($tmpTemplateDir, 0755, true);
        $testFile = $tmpTemplateDir . '/test-undo.blade.php';
        $originalContent = '<div>Original template content</div>';
        file_put_contents($testFile, $originalContent);
        $this->tempFiles[] = $testFile;

        // Build a real TemplateRegistry instance pointing to our temp dir
        $registry = new \VelaBuild\Core\Registries\TemplateRegistry();
        $refProp = (new \ReflectionClass($registry))->getProperty('templates');
        $refProp->setAccessible(true);
        $refProp->setValue($registry, ['default' => ['path' => $tmpTemplateDir]]);

        $mockVela = \Mockery::mock(\VelaBuild\Core\Vela::class);
        $mockVela->shouldReceive('templates')->andReturn($registry);
        $this->instance(\VelaBuild\Core\Vela::class, $mockVela);

        $mockProvider = \Mockery::mock(AiTextProvider::class);
        $mockProvider->shouldReceive('generateText')->andReturn('<div>Modified template content</div>');

        $mockManager = \Mockery::mock(AiProviderManager::class);
        $mockManager->shouldReceive('resolveTextProvider')->andReturn($mockProvider);
        $this->instance(AiProviderManager::class, $mockManager);

        config()->set('vela.template.active', 'default');

        $result = $this->executor->execute(
            'edit_template_file',
            ['file' => 'test-undo.blade.php', 'changes' => 'Modify the template'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertTrue($result['success'] ?? false, 'Template edit should succeed: ' . ($result['error'] ?? ''));

        $this->assertStringContainsString('Modified', file_get_contents($testFile));

        $actionLog = AiActionLog::where('conversation_id', $this->conversation->id)
            ->where('tool_name', 'edit_template_file')
            ->where('status', 'completed')
            ->latest()
            ->first();

        $this->assertNotNull($actionLog);

        $this->executor->undoAction($actionLog);

        $this->assertEquals($originalContent, file_get_contents($testFile));

        // Cleanup backup files from storage
        $backupDir = storage_path('app/template-backups');
        if (is_dir($backupDir)) {
            foreach (glob($backupDir . '/test-undo.blade.php.*.backup') ?: [] as $backup) {
                if (file_exists($backup)) {
                    unlink($backup);
                }
            }
        }

        // Remove all remaining files in the temp dir then rmdir
        if (is_dir($tmpTemplateDir)) {
            foreach (glob($tmpTemplateDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($tmpTemplateDir);
        }
    }

    public function test_action_log_status_failed_on_tool_exception(): void
    {
        $user = auth('vela')->user();
        $testKey = 'test_fail_key_' . uniqid();
        $this->createdVelaConfigKeys[] = $testKey;

        // Bind a mock UpdateSiteConfigTool that throws an exception
        $this->app->bind(UpdateSiteConfigTool::class, function () {
            $mock = \Mockery::mock(UpdateSiteConfigTool::class)->makePartial();
            $mock->shouldReceive('execute')->andThrow(new \RuntimeException('Simulated tool failure'));
            return $mock;
        });

        $result = $this->executor->execute(
            'update_site_config',
            ['key' => $testKey, 'value' => 'fail_value'],
            $this->conversation->id,
            $this->message->id,
            $user
        );

        $this->assertArrayHasKey('error', $result);

        $actionLog = AiActionLog::where('conversation_id', $this->conversation->id)
            ->where('tool_name', 'update_site_config')
            ->latest()
            ->first();

        $this->assertNotNull($actionLog);
        $this->assertEquals('failed', $actionLog->status);
    }
}
