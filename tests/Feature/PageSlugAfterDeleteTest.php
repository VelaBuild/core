<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\AiChat\Tools\UpdatePageTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Pages are soft-deleted and the table's unique index counts deleted rows, so
 * a page in the bin went on owning its address forever: delete "contact-us",
 * try to rename another page to contact-us, and the admin answers "The slug
 * has already been taken" about a page that is not there — with no trash
 * screen anywhere to free it from.
 */
class PageSlugAfterDeleteTest extends PackageTestCase
{
    use RefreshDatabase;

    private function page(string $slug, string $locale = 'en'): Page
    {
        return Page::create([
            'title' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'published',
            'locale' => $locale,
        ]);
    }

    public function test_a_deleted_page_gives_its_slug_back(): void
    {
        $old = $this->page('contact-us');
        $old->delete();

        $released = Page::releaseSlugFromTrash('contact-us', 'en');

        $this->assertSame(1, $released);
        $this->assertSame('contact-us-deleted-' . $old->id, $old->fresh()->slug);
        // The page itself is untouched otherwise, still restorable.
        $this->assertNotNull(Page::withTrashed()->find($old->id));
    }

    public function test_another_page_can_take_the_freed_slug(): void
    {
        $this->page('contact-us')->delete();
        $newer = $this->page('contact-us-2');

        $result = (new UpdatePageTool())->execute(['page_id' => $newer->id, 'slug' => 'contact-us']);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('contact-us', $newer->fresh()->slug);
    }

    public function test_a_live_page_still_owns_its_slug(): void
    {
        $this->page('services');
        $other = $this->page('services-2');

        $result = (new UpdatePageTool())->execute(['page_id' => $other->id, 'slug' => 'services']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('services-2', $other->fresh()->slug);
    }

    public function test_only_the_matching_locale_is_released(): void
    {
        $english = $this->page('contact-us', 'en');
        $thai = $this->page('contact-us', 'th');
        $english->delete();
        $thai->delete();

        Page::releaseSlugFromTrash('contact-us', 'en');

        $this->assertSame('contact-us-deleted-' . $english->id, $english->fresh()->slug);
        $this->assertSame('contact-us', $thai->fresh()->slug);
    }

    public function test_restoring_a_deleted_page_whose_slug_was_taken_gives_it_a_free_one(): void
    {
        $old = $this->page('contact-us');
        $snapshot = $old->getAttributes();
        $old->delete();
        Page::releaseSlugFromTrash('contact-us', 'en');
        $this->page('contact-us'); // someone else moved in

        $user = $this->signIn();
        $conversation = \VelaBuild\Core\Models\AiConversation::create(['user_id' => $user->id, 'title' => 'delete']);
        $message = \VelaBuild\Core\Models\AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => '']);
        $log = \VelaBuild\Core\Models\AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'user_id' => $user->id,
            'tool_name' => 'delete_page',
            'parameters' => [],
            'status' => 'completed',
            'previous_state' => ['attributes' => $snapshot, 'rows' => []],
        ]);

        (new \VelaBuild\Core\Services\AiChat\Tools\DeletePageTool())->undo($log);

        $restored = Page::find($old->id);
        $this->assertNotNull($restored, 'the page must come back even though its address was taken');
        $this->assertSame('contact-us-2', $restored->slug);
    }
}
