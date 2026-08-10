<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiChat\Tools\DeletePageTool;
use VelaBuild\Core\Services\AiChat\Tools\UpdatePageTool;
use VelaBuild\Core\Services\AiChat\Tools\UpdateSiteConfigTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Undo is the user's only route back from a chatbot mistake, so a broken undo
 * turns a recoverable action into lost work.
 */
class AiChatUndoTest extends PackageTestCase
{
    private function actionLog(string $tool): AiActionLog
    {
        $user = $this->signIn();

        $conversation = AiConversation::create(['user_id' => $user->id, 'title' => 'undo']);
        $message = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => '',
        ]);

        return AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'user_id'         => $user->id,
            'tool_name'       => $tool,
            'parameters'      => [],
            'status'          => 'completed',
        ]);
    }

    public function test_undoing_a_page_delete_restores_the_page_with_its_content(): void
    {
        $page = Page::create(['title' => 'About', 'slug' => 'undo-about', 'status' => 'published', 'locale' => 'en']);
        $row = PageRow::create(['page_id' => $page->id, 'order_column' => 0]);
        PageBlock::create(['page_row_id' => $row->id, 'type' => 'cta', 'content' => ['heading' => 'Call us']]);

        $log  = $this->actionLog('delete_page');
        $tool = new DeletePageTool();

        $this->assertTrue($tool->execute(['page_id' => $page->id], $log)['success']);
        $this->assertNull(Page::find($page->id));

        // Page uses SoftDeletes, so the row is still present — undo must restore
        // it rather than insert a second record with the same primary key.
        $tool->undo($log->fresh());

        $restored = Page::find($page->id);
        $this->assertNotNull($restored, 'the deleted page was not restored');
        $this->assertSame('undo-about', $restored->slug);
        $this->assertSame(1, $restored->rows()->count());
        $this->assertSame('Call us', $restored->rows()->first()->blocks()->first()->content['heading']);
    }

    public function test_undoing_a_page_update_restores_search_metadata(): void
    {
        $page = Page::create([
            'title'            => 'Services',
            'slug'             => 'undo-services',
            'status'           => 'published',
            'locale'           => 'en',
            'meta_title'       => 'Original title',
            'meta_description' => 'Original description',
        ]);

        $log  = $this->actionLog('update_page');
        $tool = new UpdatePageTool();

        $tool->execute(['page_id' => $page->id, 'meta_title' => 'Replaced'], $log);
        $this->assertSame('Replaced', $page->fresh()->meta_title);

        $tool->undo($log->fresh());

        $this->assertSame('Original title', $page->fresh()->meta_title);
        $this->assertSame('Original description', $page->fresh()->meta_description);
    }

    public function test_writing_a_config_key_that_never_existed_is_reported_as_a_warning(): void
    {
        // The chatbot invents plausible-looking keys ("menu_structure"). The
        // write succeeds but nothing reads it, so it must not be summarised to
        // the user as a change that took effect.
        $result = (new UpdateSiteConfigTool())->execute([
            'key'   => 'totally_invented_key',
            'value' => '{}',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('warning', $result);

        VelaConfig::where('key', 'totally_invented_key')->delete();
    }

    public function test_updating_an_existing_config_key_carries_no_warning(): void
    {
        VelaConfig::updateOrCreate(['key' => 'undo_fixture_key'], ['value' => 'before']);

        $result = (new UpdateSiteConfigTool())->execute([
            'key'   => 'undo_fixture_key',
            'value' => 'after',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('warning', $result);

        VelaConfig::where('key', 'undo_fixture_key')->delete();
    }
}
