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

    public function test_the_cards_of_a_row_are_named_and_can_be_given_a_link(): void
    {
        $page = $this->page();

        (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Features',
            'html' => '<section class="features"><div class="grid">'
                . '<div class="card"><h3>Fast</h3><p>Ships in days.</p></div>'
                . '<div class="card"><h3>Safe</h3><p>Backed up nightly.</p></div>'
                . '<div class="card"><h3>Simple</h3><p>Nothing to install.</p></div>'
                . '</div></section>',
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        // Nothing named a card before, so the editor called it "Block" and the
        // only handle on one was whatever happened to be marked inside it.
        $this->assertSame(3, substr_count($html, 'data-vela-card="c1-'));
        $this->assertMatchesRegularExpression('/data-vela-grid-count="3"/', $html);

        // And a whole card is what a visitor expects to be able to click.
        $this->assertSame(3, preg_match_all('/<div class="card" [^>]*data-vela-field-kind="linkable"/', $html));
    }

    public function test_a_card_that_already_holds_a_link_is_left_alone(): void
    {
        $page = $this->page();

        (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Features',
            'html' => '<section class="features"><div class="grid">'
                . '<div class="card"><h3>Fast</h3><a href="/fast">Read more</a></div>'
                . '<div class="card"><h3>Safe</h3><a href="/safe">Read more</a></div>'
                . '</div></section>',
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        // Wrapping the card would give the same destination twice, and the
        // second <a> inside the first is not something a browser repairs.
        $this->assertStringContainsString('data-vela-card=', $html);
        $this->assertDoesNotMatchRegularExpression('/class="card"[^>]*\blinkable\b/', $html);
        $this->assertSame(2, substr_count($html, 'data-vela-field-kind="link text"'));
    }

    public function test_a_bullet_can_be_given_a_link_and_nothing_inside_one_can(): void
    {
        $page = $this->page();

        (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Included',
            'html' => '<section class="included"><h2>What you get</h2><ul>'
                . '<li>Unlimited pages</li>'
                . '<li>Nightly backups</li>'
                . '</ul>'
                . '<a class="cta" href="/plans"><strong>See the plans</strong></a>'
                . '</section>',
        ]);

        $html = $page->rows()->first()->blocks()->first()->content['html'];

        // A bullet is a whole thing people click.
        $this->assertSame(2, preg_match_all('/<li [^>]*data-vela-field-kind="text linkable"/', $html));

        // But the wording inside a link is not offered one of its own: an <a>
        // inside an <a> closes the outer one early.
        $this->assertDoesNotMatchRegularExpression('/<strong[^>]*\blinkable\b/', $html);
    }

    /**
     * Stage a theme the build could have written, and clean it up after.
     *
     * The check reads the theme's ink token, which only a theme written from
     * the skeleton declares — the shipped ones compile their colours into a
     * bundle. That is the case that matters: the preview page a build adds
     * sections to always wears a theme the build wrote.
     */
    private function stageAWrittenTheme(): void
    {
        $theme = app(\VelaBuild\Core\Services\ThemeAuthor::class)->scaffold('lantern', 'Lantern');
        app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->setTheme($theme);

        $this->beforeApplicationDestroyed(function () {
            \Illuminate\Support\Facades\File::deleteDirectory(resource_path('views/templates/lantern'));
        });
    }

    public function test_a_dark_section_that_leaves_its_headings_to_the_theme_is_refused(): void
    {
        $this->stageAWrittenTheme();
        $page = $this->page();

        // What a build wrote: colour on the container, nothing on the heading.
        // The theme colours h1 by name, and that beats inheritance, so the
        // headline came out as the theme's ink on the section's own dark
        // ground — 1.28:1, invisible, on a page that looked finished.
        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => '<section class="hero"><h1>Build Your Authority</h1><p>Lorem ipsum dolor sit amet.</p></section>',
            'css' => '.hero{background-color:#1a1a1a;color:#ffffff;text-align:center;padding:50px;font-size:48px}',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('nobody can read', $result['error']);
        $this->assertSame(0, $page->rows()->count(), 'and the section does not reach the page');
    }

    public function test_the_same_section_is_accepted_once_it_colours_its_own_headings(): void
    {
        $this->stageAWrittenTheme();
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => '<section class="hero"><h1>Build Your Authority</h1><p>Lorem ipsum dolor sit amet.</p></section>',
            'css' => '.hero{background-color:#1a1a1a;padding:50px;text-align:center}'
                . '.hero h1{color:#ffffff;font-size:48px;font-weight:700;letter-spacing:-0.02em}'
                . '.hero p{color:#d8d8d8;font-size:18px;line-height:1.6}',
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? '');
    }

    public function test_a_light_section_is_left_alone(): void
    {
        $this->stageAWrittenTheme();
        $page = $this->page();

        // The theme's ink reads perfectly well on a pale ground; asking for a
        // heading colour there would be a guard firing on nothing.
        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Features',
            'html' => '<section class="feat"><h2>What you get</h2><p>Unlimited pages, backed up nightly.</p></section>',
            'css' => '.feat{background-color:#f8f4eb;padding:64px 24px}'
                . '.feat h2{font-size:36px;font-weight:700;margin-bottom:16px}'
                . '.feat p{font-size:18px;line-height:1.7}',
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? '');
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
    public function test_a_section_whose_links_go_nowhere_is_refused(): void
    {
        $page = $this->page();

        // What a build wrote in place of a posts_grid: three cards of lorem
        // ipsum with three dead links, a listing frozen into markup on the day
        // it was built.
        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Latest Insights',
            'html' => '<section class="insights"><h2>Latest Insights</h2>'
                . '<article class="card"><h3>The Future of Digital Workspaces</h3><a href="#">Read More</a></article>'
                . '<article class="card"><h3>Innovation in Corporate Leadership</h3><a href="#">Read More</a></article>'
                . '</section>',
            'css' => '.insights{background:#ffffff;padding:64px}.insights h2{font-size:36px;color:#111}'
                . '.insights .card{background:#f8f8f8;border-radius:8px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06)}'
                . '.insights h3{font-size:20px;color:#222;margin-bottom:8px}',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(2, $result['dead_links']);
        // And it is told what a list of the site's own articles should be.
        $this->assertStringContainsString('posts_grid', $result['error']);
        $this->assertSame(0, $page->rows()->count());
    }

    public function test_a_section_whose_links_have_somewhere_to_go_is_accepted(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Call to Action',
            'html' => '<section class="cta"><h2>Ready to start your project?</h2>'
                . '<a class="cta-btn" href="/contact">Get a free consultation</a></section>',
            'css' => '.cta{background:#1a6b7a;padding:48px;text-align:center}'
                . '.cta h2{color:#ffffff;font-size:30px;font-weight:700;margin-bottom:20px}'
                . '.cta-btn{background:#ffffff;color:#1a6b7a;padding:14px 28px;border-radius:4px;font-weight:600}',
        ]);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? '');
    }

    public function test_a_placeholder_picture_service_is_refused(): void
    {
        $page = $this->page();

        // What a build wrote into all six cards of a page. via.placeholder.com
        // has stopped answering, so every one of them rendered as a broken
        // image on a site that otherwise looked finished.
        foreach ([
            'https://via.placeholder.com/150',
            'https://placehold.co/600x400',
            'https://picsum.photos/600/400',
            'https://dummyimage.com/600x400',
        ] as $url) {
            $result = (new AddDesignedSectionTool())->execute([
                'page_id' => $page->id,
                'name' => 'Features',
                'html' => '<section class="feat"><h2>Business Strategy</h2><img src="' . $url . '" alt="Strategy">'
                    . '<p>Lorem ipsum dolor sit amet.</p></section>',
                'css' => '.feat{background:#ffffff;padding:48px}.feat h2{font-size:24px;color:#111}'
                    . '.feat img{width:100%;border-radius:8px}.feat p{font-size:16px;color:#555;line-height:1.6}',
            ]);

            $this->assertArrayHasKey('error', $result, $url . ' should be refused');
            $this->assertStringContainsString('placeholder picture service', $result['error']);
            // And it is told what this site ships for exactly this.
            $this->assertSame(\VelaBuild\Core\Services\DesignBuilderService::PLACEHOLDER, $result['use_this_url']);
        }

        $this->assertSame(0, $page->rows()->count());
    }

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

    /**
     * A build wrote five sections and the page's whole styling came to five
     * rules — display:flex on the hero, a three-column grid on the cards,
     * list-style on the topics. The arrangement was right and nothing else
     * was, so a magazine design came out as the theme's defaults in the
     * design's running order, which reads as no styling at all.
     */
    public function test_a_section_that_is_placed_but_not_designed_is_refused(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
            // Verbatim from the run that prompted this.
            'css' => '.hero{display:flex;align-items:center}'
                . '.hero-copy{flex:1}'
                . '.hero-shot{flex:1}',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('places the section but does not design it', $result['error']);
        $this->assertSame(0, $page->rows()->count());
    }

    public function test_a_stylesheet_that_says_how_the_section_looks_is_accepted(): void
    {
        $page = $this->page();

        $result = (new AddDesignedSectionTool())->execute([
            'page_id' => $page->id,
            'name' => 'Hero',
            'html' => $this->hero(),
            'css' => '.hero{display:grid;grid-template-columns:7fr 5fr;padding:96px 32px}'
                . '.hero-title{font-size:64px;font-weight:800;color:#101828}',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
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
