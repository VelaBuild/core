<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\AiChat\SectionImporter;
use VelaBuild\Core\Services\AiChat\Tools\CopyPageTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Told to copy a page, the model rebuilt it out of page-builder blocks and the
 * result looked nothing like the original — one copy opened with a "Skip to
 * content" text block and the source site's navigation rendered as feature
 * boxes. copy_page removes the choice: outline, drop the furniture, import
 * every content section.
 */
class AiChatCopyPageTest extends PackageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SectionImporter::flushCache();
    }

    private function fakeSource(): void
    {
        $html = '<html><head><link rel="stylesheet" href="/app.css"></head><body>'
            . '<a class="skip" href="#main">Skip to content</a>'
            . '<header class="site-header"><nav class="navbar"><a href="/">Acme</a><a href="/pricing">Pricing</a><a href="/docs">Docs</a></nav></header>'
            . '<main>'
            . '<section class="hero"><h1>Simple pricing</h1><p>Pay for what you use, nothing more, cancel whenever you like.</p>'
            . '<a class="btn" href="/signup">Start free</a></section>'
            . '<section class="tiers"><h2>Plans</h2><div class="grid">'
            . '<div class="tier"><h3>Hobby</h3><p>Free forever for personal projects and prototypes.</p></div>'
            . '<div class="tier"><h3>Pro</h3><p>Twenty dollars a month per member of your team.</p></div>'
            . '<div class="tier"><h3>Enterprise</h3><p>Talk to us about volume and compliance needs.</p></div>'
            . '</div></section>'
            . '<section class="logos"><h2>Trusted by teams</h2><img src="/img/one.png" alt="One"><img src="/img/two.png" alt="Two"></section>'
            . '</main>'
            . '<footer class="site-footer"><p>© Acme 2026. All rights reserved worldwide.</p></footer>'
            . '</body></html>';

        Http::fake([
            'acme.example/app.css' => Http::response('.hero{padding:80px 0}.tier{border-radius:12px}', 200, ['content-type' => 'text/css']),
            'acme.example/img/*' => Http::response('png-bytes', 200, ['content-type' => 'image/png']),
            'acme.example/*' => Http::response($html, 200, ['content-type' => 'text/html']),
        ]);
    }

    public function test_it_creates_a_draft_page_holding_every_content_section(): void
    {
        Storage::fake();
        $this->fakeSource();

        $result = (new CopyPageTool())->execute([
            'url' => 'https://acme.example/pricing',
            'title' => 'Pricing',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');

        $page = Page::find($result['page_id']);
        $this->assertSame('Pricing', $page->title);
        $this->assertSame('pricing', $page->slug);
        // Publishing someone else's page is the user's call, not the tool's.
        $this->assertSame('draft', $page->status);
        $this->assertSame(3, $result['sections_copied']);
        $this->assertSame(3, $page->rows()->count());

        $html = '';
        foreach ($page->rows()->with('blocks')->get() as $row) {
            foreach ($row->blocks as $block) {
                $html .= $block->content['html'];
            }
        }
        $this->assertStringContainsString('Simple pricing', $html);
        $this->assertStringContainsString('Enterprise', $html);
        $this->assertStringContainsString('Trusted by teams', $html);
        // The source styling came with it, scoped to those sections.
        $this->assertStringContainsString('padding:80px0', str_replace(' ', '', $page->custom_css));
    }

    public function test_it_leaves_out_the_source_sites_furniture(): void
    {
        Storage::fake();
        $this->fakeSource();

        $result = (new CopyPageTool())->execute(['url' => 'https://acme.example/pricing', 'title' => 'Pricing']);

        $html = '';
        foreach (Page::find($result['page_id'])->rows()->with('blocks')->get() as $row) {
            foreach ($row->blocks as $block) {
                $html .= $block->content['html'];
            }
        }

        $this->assertStringNotContainsString('Skip to content', $html);
        $this->assertStringNotContainsString('navbar', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertNotEmpty($result['skipped']);
    }

    public function test_a_one_line_heading_section_is_still_copied(): void
    {
        Storage::fake();
        Http::fake([
            'acme.example/*' => Http::response(
                '<html><body><a href="#main">Skip to content</a><main>'
                . '<section class="title"><h1>Scale your app, control your costs</h1></section>'
                . '<section class="tiers"><h2>Plans</h2><div class="grid">'
                . '<div class="tier"><h3>Hobby</h3><p>Free forever for personal projects.</p></div>'
                . '<div class="tier"><h3>Pro</h3><p>Twenty dollars per member each month.</p></div>'
                . '</div></section></main></body></html>',
                200,
                ['content-type' => 'text/html']
            ),
        ]);

        $result = (new CopyPageTool())->execute(['url' => 'https://acme.example/pricing', 'title' => 'Pricing']);

        $html = '';
        foreach (Page::find($result['page_id'])->rows()->with('blocks')->get() as $row) {
            foreach ($row->blocks as $block) {
                $html .= $block->content['html'];
            }
        }

        // The page's own headline is short; dropping it as "too small" opened
        // the copy halfway down the page.
        $this->assertStringContainsString('Scale your app', $html);
        $this->assertStringNotContainsString('Skip to content', $html);
    }

    public function test_the_copied_sections_are_editable_in_the_page_builder(): void
    {
        Storage::fake();
        $this->fakeSource();

        $result = (new CopyPageTool())->execute(['url' => 'https://acme.example/pricing', 'title' => 'Pricing']);

        $this->assertGreaterThan(0, $result['editable_fields']);
        $this->assertSame(2, $result['images_saved']);
    }

    public function test_sections_can_be_added_to_an_existing_page(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = Page::create(['title' => 'Plans', 'slug' => 'plans', 'status' => 'published', 'locale' => config('vela.primary_language', 'en')]);

        $result = (new CopyPageTool())->execute(['url' => 'https://acme.example/pricing', 'page_id' => $page->id]);

        $this->assertSame($page->id, $result['page_id']);
        $this->assertSame('published', $page->fresh()->status);
        $this->assertSame(3, $page->rows()->count());
    }

    public function test_undo_removes_a_page_this_call_created(): void
    {
        Storage::fake();
        $this->fakeSource();

        $user = $this->signIn();
        $conversation = \VelaBuild\Core\Models\AiConversation::create(['user_id' => $user->id, 'title' => 'copy']);
        $message = \VelaBuild\Core\Models\AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => '']);
        $log = \VelaBuild\Core\Models\AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'user_id' => $user->id,
            'tool_name' => 'copy_page',
            'parameters' => [],
            'status' => 'completed',
        ]);

        $tool = new CopyPageTool();
        $result = $tool->execute(['url' => 'https://acme.example/pricing', 'title' => 'Pricing'], $log);
        $pageId = $result['page_id'];

        $tool->undo($log->fresh());

        $this->assertNull(Page::find($pageId));
    }

    public function test_it_says_when_the_page_yields_no_sections(): void
    {
        Http::fake(['empty.example/*' => Http::response('<html><body></body></html>', 200, ['content-type' => 'text/html'])]);

        $result = (new CopyPageTool())->execute(['url' => 'https://empty.example/', 'title' => 'Nothing']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Page::where('slug', 'nothing')->count());
    }
}
