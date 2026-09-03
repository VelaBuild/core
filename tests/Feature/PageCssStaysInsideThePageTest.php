<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\AiChat\Tools\UpdateCustomCssTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A page's stylesheet must stay inside that page.
 *
 * A design build wrote a "mockup frame" into one page's CSS: it painted the
 * document's ground, hung decorative shapes off html::before/::after, and
 * boxed the header, content and footer to 1000px inside 24px black borders.
 * The old guard only refused an EXACT `html` / `body` / `:root` selector, so
 * `html:has(.page-id-26)` walked straight through — and the frame outlived the
 * sections it was written for, squeezing a theme's plain example homepage
 * inside somebody else's design with nothing on screen to explain it.
 */
class PageCssStaysInsideThePageTest extends PackageTestCase
{
    private function page(): Page
    {
        return Page::create([
            'title' => 'Home', 'slug' => 'home', 'status' => 'published',
            'locale' => config('vela.primary_language', 'en'),
        ]);
    }

    public function test_a_selector_that_starts_at_the_document_is_refused_however_it_is_dressed(): void
    {
        $page = $this->page();

        foreach ([
            'html:has(.page-id-26){background:#ededed;}',
            'html:has(.page-id-26)::before{content:"";background:#f0b895;}',
            'body.page-home{background:#fff;}',
            ':root[data-x]{color:#000;}',
            'html{background:#eee;}',
        ] as $css) {
            $result = (new UpdateCustomCssTool)->execute(['scope' => 'page', 'page_id' => $page->id, 'css' => $css]);

            $this->assertArrayHasKey('error', $result, $css);
        }
    }

    public function test_a_page_may_not_restyle_the_frame_the_theme_draws(): void
    {
        $page = $this->page();

        $result = (new UpdateCustomCssTool)->execute([
            'scope' => 'page',
            'page_id' => $page->id,
            'css' => '.site-header{max-width:1000px;border-left:24px solid #030301;}',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('.site-header', $result['blocked_selector']);
    }

    public function test_a_pages_own_sections_are_still_free_to_be_styled(): void
    {
        $page = $this->page();
        $row = $page->rows()->create(['name' => 'Hero', 'css_class' => '', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'html',
            'content' => ['html' => '<div class="vela-design-abc"><section class="dm-hero">Hello</section></div>'],
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        $result = (new UpdateCustomCssTool)->execute([
            'scope' => 'page',
            'page_id' => $page->id,
            'css' => '.vela-design-abc .dm-hero{background:#fefefe;padding:40px 62px;}',
        ]);

        // Neither of the two guards added here has anything to say about a
        // section styling itself. (Whether the older "these classes are not in
        // any block" check is happy is its own business and its own force
        // flag — this test is about not having broken the normal case.)
        $error = $result['error'] ?? '';
        $this->assertStringNotContainsString('the THEME draws on every page', $error);
        $this->assertStringNotContainsString('Typography, the ground colour', $error);
    }

    /**
     * A site-wide stylesheet is loaded on every page, so the header is a fair
     * thing for it to style — the furniture rule is for page scope only. (It
     * still meets the older "these classes are not in any block" check, which
     * is a different guard with its own force flag.)
     */
    public function test_the_furniture_rule_does_not_apply_to_the_sitewide_stylesheet(): void
    {
        Gate::define('config_edit', fn () => true);

        $result = (new UpdateCustomCssTool)->execute([
            'scope' => 'site',
            'css' => '.site-header{box-shadow:0 1px 2px rgba(0,0,0,.1);}',
        ]);

        $this->assertStringNotContainsString('the THEME draws on every page', $result['error'] ?? '');
    }
}
