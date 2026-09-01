<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiChat\Tools\SwitchTemplateTool;
use VelaBuild\Core\Services\AiChat\Tools\UpdateCustomCssTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Settings live in vela_configs, but no request reads that table — the
 * provider boots `vela.*` from storage/app/vela-site.php. A tool that wrote
 * the row and stopped left the site serving the old value until somebody
 * saved something in the admin, so switching theme reported success while
 * every visitor kept seeing the previous one.
 */
class AiChatSiteConfigCacheTest extends PackageTestCase
{
    private function cacheFile(): string
    {
        return storage_path('app/vela-site.php');
    }

    private function cachedConfig(): array
    {
        $this->assertFileExists($this->cacheFile(), 'the tool must leave a cache for the site to read');

        return (array) include $this->cacheFile();
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile());
        parent::tearDown();
    }

    public function test_the_changing_process_renders_with_the_new_setting(): void
    {
        // The process that changed the theme still held what it booted with,
        // so anything it rendered afterwards — a page snapshot rebuilt later
        // in the same request — was written with the theme just replaced, and
        // a visitor was served it.
        config(['vela.template.active' => 'modern']);

        (new SwitchTemplateTool())->execute(['template' => 'dark']);

        $this->assertSame('dark', config('vela.template.active'));
    }

    public function test_switching_theme_reaches_the_file_the_site_renders_from(): void
    {
        $result = (new SwitchTemplateTool())->execute(['template' => 'dark']);

        $this->assertTrue($result['success']);
        $this->assertSame('dark', $this->cachedConfig()['active_template']);
    }

    public function test_undoing_a_theme_switch_reaches_it_too(): void
    {
        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => 'modern']);

        $log = $this->actionLog();
        (new SwitchTemplateTool())->execute(['template' => 'dark'], $log);
        (new SwitchTemplateTool())->undo($log->fresh());

        $this->assertSame('modern', $this->cachedConfig()['active_template']);
    }

    public function test_sitewide_css_reaches_it_as_well(): void
    {
        // Same failure, different setting: the stylesheet is read from the
        // cached config, so a visitor saw none of it.
        // A block class rather than `body`: the ground colour of the document
        // is the theme's to set, and the tool now says so. What this test is
        // about is the cache, not which selector was used.
        (new UpdateCustomCssTool())->execute([
            'scope' => 'site',
            'css'   => '.block-hero { background: #f3eada; }',
        ]);

        $this->assertStringContainsString('#f3eada', $this->cachedConfig()['custom_css_global']);
    }

    private function actionLog(): \VelaBuild\Core\Models\AiActionLog
    {
        $user = $this->signIn();

        $conversation = \VelaBuild\Core\Models\AiConversation::create([
            'user_id' => $user->id,
            'title'   => 'Theme',
        ]);

        $message = \VelaBuild\Core\Models\AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => '',
        ]);

        return \VelaBuild\Core\Models\AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'user_id'         => $user->id,
            'tool_name'       => 'switch_template',
            'parameters'      => [],
            'status'          => 'completed',
        ]);
    }
}
