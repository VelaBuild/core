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

        $this->assertTrue($tool->execute(['page_id' => $page->id, 'confirm' => true], $log)['success']);
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

    public function test_a_page_is_not_deleted_until_the_deletion_is_confirmed(): void
    {
        // "delete all the pages on my website" once went straight through. The
        // refusal is also the text to show the user, so it names what is lost.
        $page = Page::create(['title' => 'Keep me', 'slug' => 'keep-me', 'status' => 'published', 'locale' => 'en']);
        $row = PageRow::create(['page_id' => $page->id, 'order_column' => 0]);
        PageBlock::create(['page_row_id' => $row->id, 'type' => 'cta', 'content' => ['heading' => 'Hi']]);

        $tool = new DeletePageTool();

        $refused = $tool->execute(['page_id' => $page->id]);
        $this->assertTrue($refused['needs_confirmation']);
        $this->assertSame(1, $refused['page']['rows']);
        $this->assertSame(1, $refused['page']['blocks']);
        $this->assertNotNull(Page::find($page->id), 'the page was deleted without confirmation');

        $this->assertTrue($tool->execute(['page_id' => $page->id, 'confirm' => true])['success']);
        $this->assertNull(Page::find($page->id));
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

    public function test_a_config_key_that_never_existed_is_refused_rather_than_written(): void
    {
        // Asked to "turn on the newsletter popup", the chatbot invents a
        // plausible key. Storing it changes nothing on the site, leaves a row
        // no code reads, and shifts the job of checking onto the user.
        $result = (new UpdateSiteConfigTool())->execute([
            'key'   => 'newsletter_popup_enabled',
            'value' => 'true',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, VelaConfig::where('key', 'newsletter_popup_enabled')->count());
    }

    public function test_a_new_key_can_still_be_created_deliberately(): void
    {
        $result = (new UpdateSiteConfigTool())->execute([
            'key'        => 'deliberate_new_key',
            'value'      => 'on',
            'create_new' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('on', VelaConfig::where('key', 'deliberate_new_key')->value('value'));

        VelaConfig::where('key', 'deliberate_new_key')->delete();
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
