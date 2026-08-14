<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\AiChat\Tools\ImportPageSectionTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Rebuilding a copied page out of blocks reproduces its arrangement and loses
 * its design — the user's words were "เหมือนแค่ 20%". Importing the section
 * itself is the path that actually resembles the original, so what it brings
 * across (and what it refuses to bring) is worth pinning down.
 */
class AiChatImportSectionTest extends PackageTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The importer holds fetched pages for a few minutes so copying nine
        // sections is not nine downloads; each test serves its own page.
        \VelaBuild\Core\Services\AiChat\SectionImporter::flushCache();
    }

    private function fakeSource(): void
    {
        $html = '<html><head>'
            . '<link rel="stylesheet" href="/assets/app.css">'
            . '<style>.hero{padding:96px 0}.unused-widget{color:red}</style>'
            . '</head><body><main>'
            . '<section class="hero" id="top"><h1 class="title">Ship faster</h1>'
            . '<p class="lead">Launch in days rather than quarters, with less ceremony.</p>'
            . '<a class="btn" href="/signup" onclick="track()">Start free</a>'
            . '<img src="/img/hero.png" alt="App">'
            . '<script>evil()</script></section>'
            . '<section class="pricing"><h2>Plans</h2><p>From $9 a month for small teams.</p></section>'
            . '</main></body></html>';

        Http::fake([
            'acme.example/assets/app.css' => Http::response('.title{font-size:56px;font-weight:800}.btn{background:#ff5a1f;border-radius:999px}.hero{background:url(../img/bg.jpg)}', 200, ['content-type' => 'text/css']),
            'acme.example/img/hero.png' => Http::response('png-bytes', 200, ['content-type' => 'image/png']),
            'acme.example/*' => Http::response($html, 200, ['content-type' => 'text/html']),
        ]);
    }

    private function page(): Page
    {
        return Page::create([
            'title' => 'Landing',
            'slug' => 'landing',
            'status' => 'draft',
            'locale' => config('vela.primary_language', 'en'),
        ]);
    }

    public function test_it_brings_markup_styling_and_pictures_across(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'section_index' => 1,
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');

        $block = $page->rows()->first()->blocks()->first();
        $html = $block->content['html'];
        $css = (string) $page->fresh()->custom_css;

        $this->assertSame('html', $block->type);
        $this->assertStringContainsString('Ship faster', $html);
        $this->assertStringContainsString('Launch in days', $html);
        // The source's own styling, reaching only inside this section.
        $this->assertStringContainsString('font-size:56px', str_replace(' ', '', $css));
        $this->assertStringContainsString('.' . $result['wrapper_class'] . ' .title', $css);
        $this->assertStringNotContainsString('unused-widget', $css);
        // The picture is ours now, not a hotlink.
        $this->assertSame(1, $result['images_saved']);
        $this->assertStringNotContainsString('acme.example/img/hero.png', $html);
    }

    public function test_nothing_executable_survives_the_import(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();

        (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'section_index' => 1,
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('evil()', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function test_the_section_index_matches_the_outline_numbering(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'section_index' => 2,
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Plans', $html);
        $this->assertStringNotContainsString('Ship faster', $html);
    }

    public function test_a_selector_may_be_used_instead_of_an_index(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'selector' => '.pricing',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Plans', $page->rows()->first()->blocks()->first()->content['html']);
    }

    public function test_importing_the_same_section_twice_replaces_its_css_rather_than_stacking_it(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();
        $tool = new ImportPageSectionTool();
        $arguments = ['url' => 'https://acme.example/', 'page_id' => $page->id, 'section_index' => 1];

        $tool->execute($arguments);
        $first = strlen((string) $page->fresh()->custom_css);
        $tool->execute($arguments);
        $second = strlen((string) $page->fresh()->custom_css);

        $this->assertSame($first, $second);
    }

    public function test_it_refuses_the_source_sites_navbar_header_and_footer(): void
    {
        Storage::fake();
        Http::fake(['acme.example/*' => Http::response(
            '<html><body>'
            . '<header class="site-header"><nav class="navbar"><a href="/">Acme</a><a href="/pricing">Pricing</a><a href="/about">About</a></nav></header>'
            . '<main><section class="hero"><h1>Ship faster</h1><p>Launch in days rather than quarters.</p></section></main>'
            . '<footer class="site-footer"><p>© Acme 2026. All rights reserved worldwide.</p></footer>'
            . '</body></html>',
            200,
            ['content-type' => 'text/html']
        )]);
        $page = $this->page();
        $tool = new ImportPageSectionTool();

        foreach (['header', '.navbar', 'footer'] as $selector) {
            $result = $tool->execute([
                'url' => 'https://acme.example/',
                'page_id' => $page->id,
                'selector' => $selector,
            ]);

            $this->assertArrayHasKey('error', $result, "{$selector} should not be importable");
            $this->assertStringContainsString('this site draws for itself', $result['error']);
        }

        $this->assertSame(0, $page->rows()->count());

        // …but the page's own content is imported as normal.
        $this->assertTrue($tool->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'selector' => '.hero',
        ])['success']);
    }

    public function test_furniture_can_still_be_imported_deliberately(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'selector' => '.hero',
            'force' => true,
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_stylesheets_are_found_however_the_rel_attribute_is_written(): void
    {
        Storage::fake();
        Http::fake([
            'acme.example/a.css' => Http::response('.hero{padding:120px 0}', 200, ['content-type' => 'text/css']),
            'acme.example/b.css' => Http::response('.title{letter-spacing:-2px}', 200, ['content-type' => 'text/css']),
            'acme.example/print.css' => Http::response('.hero{display:none}', 200, ['content-type' => 'text/css']),
            'acme.example/*' => Http::response(
                '<html><head>'
                . '<link rel="Stylesheet Preload" href="/a.css">'
                . '<link rel="preload" as="style" href="/b.css">'
                . '<link rel="stylesheet" media="print" href="/print.css">'
                . '</head><body><section class="hero"><h1 class="title">Ship faster</h1><p>Launch in days.</p></section></body></html>',
                200,
                ['content-type' => 'text/html']
            ),
        ]);
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'selector' => '.hero',
        ]);

        $css = (string) $page->fresh()->custom_css;

        $this->assertContains('https://acme.example/a.css', $result['stylesheets_read']);
        $this->assertContains('https://acme.example/b.css', $result['stylesheets_read']);
        $this->assertStringContainsString('padding:120px0', str_replace(' ', '', $css));
        $this->assertStringContainsString('letter-spacing:-2px', str_replace(' ', '', $css));
        // Print styling would hide the section on screen if it came across.
        $this->assertStringNotContainsString('display:none', str_replace(' ', '', $css));
    }

    public function test_it_says_why_the_styling_did_not_come_across(): void
    {
        Storage::fake();
        Http::fake([
            'acme.example/app.css' => Http::response('blocked by the cdn', 403),
            'acme.example/*' => Http::response(
                '<html><head><link rel="stylesheet" href="/app.css"></head>'
                . '<body><section class="hero"><h1>Ship faster</h1><p>Launch in days rather than quarters.</p></section></body></html>',
                200,
                ['content-type' => 'text/html']
            ),
        ]);
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'selector' => '.hero',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('refused to download', $result['css_warning']);
        $this->assertSame(['https://acme.example/app.css'], $result['stylesheets_failed']);
    }

    public function test_it_says_which_section_it_could_not_find(): void
    {
        $this->fakeSource();
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'selector' => '.nonexistent',
        ]);

        $this->assertStringContainsString('No element matched', $result['error']);
    }

    public function test_it_refuses_without_a_target_section(): void
    {
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
        ]);

        $this->assertStringContainsString('section_index', $result['error']);
    }

    public function test_undo_removes_the_section_and_restores_the_page_css(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();
        $page->update(['custom_css' => '.page-content{max-width:900px}']);

        $user = $this->signIn();
        $conversation = \VelaBuild\Core\Models\AiConversation::create(['user_id' => $user->id, 'title' => 'import']);
        $message = \VelaBuild\Core\Models\AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
        ]);
        $log = \VelaBuild\Core\Models\AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'user_id' => $user->id,
            'tool_name' => 'import_page_section',
            'parameters' => [],
            'status' => 'completed',
        ]);

        $tool = new ImportPageSectionTool();
        $tool->execute(['url' => 'https://acme.example/', 'page_id' => $page->id, 'section_index' => 1], $log);
        $this->assertSame(1, $page->rows()->count());

        $tool->undo($log->fresh());

        $this->assertSame(0, $page->fresh()->rows()->count());
        $this->assertSame('.page-content{max-width:900px}', $page->fresh()->custom_css);
    }

    public function test_wording_pictures_and_links_are_marked_for_the_page_builder(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'section_index' => 1,
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        // Without these marks the page builder can only offer its owner a
        // textarea of raw HTML, and they cannot change a word of their page.
        $this->assertGreaterThan(0, $result['editable_fields']);
        $this->assertMatchesRegularExpression('/<h1[^>]+data-vela-field="f\d+"[^>]+data-vela-field-kind="text"/', $html);
        $this->assertMatchesRegularExpression('/<img[^>]+data-vela-field-kind="image"/', $html);
        // The button is both wording and a destination.
        $this->assertMatchesRegularExpression('/<a[^>]+data-vela-field-kind="link text"/', $html);
    }

    public function test_containers_are_not_marked_as_editable_text(): void
    {
        Storage::fake();
        $this->fakeSource();
        $page = $this->page();

        (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'section_index' => 1,
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        // Marking a wrapper as text would let one edit replace everything
        // inside it — the whole section collapsing into a single string.
        $this->assertDoesNotMatchRegularExpression('/<section[^>]+data-vela-field-kind="[^"]*text/', $html);
        $this->assertDoesNotMatchRegularExpression('/<div[^>]+data-vela-field-kind="[^"]*text/', $html);
    }

    public function test_a_heading_split_by_a_line_break_is_still_editable(): void
    {
        Storage::fake();
        Http::fake(['acme.example/*' => Http::response(
            '<html><body><main><section class="hero">'
            . '<h1>Scale your app,<br>control your costs</h1>'
            . '<p>Pay for what you use and nothing else at all.</p>'
            . '</section></main></body></html>',
            200,
            ['content-type' => 'text/html']
        )]);
        $page = $this->page();

        $result = (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'selector' => '.hero',
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        // A <br> used to make the heading look like a container, which left
        // the page's own headline with no way to edit it at all.
        $this->assertGreaterThan(0, $result['editable_fields']);
        $this->assertMatchesRegularExpression('/<h1[^>]*data-vela-field-multiline="1"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*data-vela-field-kind="text"[^>]*>/', $html);
        $this->assertStringContainsString('<br>', $html);
    }

    public function test_form_controls_are_marked_so_they_can_be_reworded_or_left_out(): void
    {
        Storage::fake();
        Http::fake(['acme.example/*' => Http::response(
            '<html><body><main><section class="contact"><h2>Talk to us</h2>'
            . '<form><label for="email">Company email</label>'
            . '<input id="email" type="email" placeholder="you@company.com">'
            . '<select id="country"><option>Thailand</option></select>'
            . '<textarea id="msg" placeholder="How can we help?"></textarea>'
            . '<input type="hidden" name="csrf"><button type="submit">Send</button>'
            . '</form></section></main></body></html>',
            200,
            ['content-type' => 'text/html']
        )]);
        $page = $this->page();

        (new ImportPageSectionTool())->execute([
            'url' => 'https://acme.example/',
            'page_id' => $page->id,
            'selector' => '.contact',
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        // A control holds no words of its own, so nothing marked it and the
        // page builder offered no way to reword it or leave it out.
        $this->assertSame(3, substr_count($html, 'data-vela-field-kind="control"'));
        $this->assertMatchesRegularExpression('/<input[^>]*id="email"[^>]*data-vela-field-kind="control"/', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*data-vela-field-kind="control"/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*data-vela-field-kind="control"/', $html);
        // A hidden input and the submit button are not fields anyone edits.
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="hidden"[^>]*data-vela-field/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-vela-field-kind="[^"]*control/', $html);
    }
}
