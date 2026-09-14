<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;
use VelaBuild\Core\Vela;

/**
 * A block with no frame of its own is held to the page's measure, even in a
 * full-width row.
 *
 * The design build puts every section it writes in a full-width row, because
 * each section brings its own container in its markup. A video added under a
 * designed hero then ran the width of the screen while every section around
 * it sat at the page's measure — reported as "why is my video not the same
 * width as the other sections".
 *
 * Banners are the other side of it: the default theme's example homepage puts
 * a hero and a call to action in full-width rows because they are MADE to run
 * edge to edge. Holding everything to the measure would have shrunk those, so
 * a block type says which it is.
 */
class BlocksInAFullWidthRowTest extends PackageTestCase
{
    private function pageWith(string $width, array $blocks): Page
    {
        $page = Page::create([
            'title' => 'Width',
            'slug' => 'width-check',
            'locale' => 'en',
            'status' => 'published',
        ]);

        $row = $page->rows()->create(['name' => 'Hero', 'width' => $width, 'padding' => '0', 'order_column' => 0]);

        foreach ($blocks as $order => [$type, $content]) {
            $row->blocks()->create([
                'type' => $type,
                'content' => $content,
                'column_index' => 0,
                'column_width' => 12,
                'order_column' => $order,
            ]);
        }

        return $page->fresh('rows.blocks');
    }

    /** @return array<string, string> the class attribute of each block, by type */
    private function blockClasses(Page $page): array
    {
        $html = $this->get('/' . $page->slug)->assertOk()->getContent();
        $classes = [];

        foreach ($page->rows->first()->blocks as $block) {
            preg_match('/id="block-' . $block->id . '" class="([^"]*)"/', $html, $m);
            $classes[$block->type . '#' . $block->id] = preg_replace('/\s+/', ' ', trim($m[1] ?? 'NOT RENDERED'));
        }

        return $classes;
    }

    public function test_a_video_under_a_designed_section_keeps_to_the_measure(): void
    {
        $page = $this->pageWith('full', [
            ['html', ['html' => '<div class="vela-design-60290ed68f" data-vela-block="b1"><section><h1>Supercharge</h1></section></div>']],
            ['video', ['url' => 'https://www.youtube.com/watch?v=RK3QuH9avVA']],
        ]);

        $classes = array_values($this->blockClasses($page));

        $this->assertStringNotContainsString('page-block-contained', $classes[0], 'the section brings its own container');
        $this->assertStringContainsString('page-block-contained', $classes[1], 'the video has none, so the page gives it one');
    }

    public function test_a_copied_section_runs_edge_to_edge_as_it_always_has(): void
    {
        $page = $this->pageWith('full', [
            ['html', ['html' => '<div class="vela-import-abc" data-vela-block="b2"><section>Copied</section></div>']],
        ]);

        $this->assertStringNotContainsString('page-block-contained', array_values($this->blockClasses($page))[0]);
    }

    /** The default theme's homepage depends on this: a banner is made to bleed. */
    public function test_a_banner_still_runs_edge_to_edge(): void
    {
        $page = $this->pageWith('full', [
            ['hero', ['title' => 'Welcome']],
            ['cta', ['title' => 'Join us']],
        ]);

        foreach ($this->blockClasses($page) as $type => $class) {
            $this->assertStringNotContainsString('page-block-contained', $class, $type . ' is a banner');
        }

        $this->assertTrue(app(Vela::class)->blocks()->bleeds('hero'));
        $this->assertTrue(app(Vela::class)->blocks()->bleeds('cta'));
        $this->assertFalse(app(Vela::class)->blocks()->bleeds('video'));
    }

    /** In a contained row the row already does the holding, so nothing is added. */
    public function test_a_contained_row_is_left_alone(): void
    {
        $page = $this->pageWith('contained', [
            ['video', ['url' => 'https://www.youtube.com/watch?v=RK3QuH9avVA']],
            ['text', ['text' => 'Hello']],
        ]);

        foreach ($this->blockClasses($page) as $type => $class) {
            $this->assertStringNotContainsString('page-block-contained', $class, $type);
        }
    }

    /** A block type registered without saying — a plugin's, say — has no frame. */
    public function test_an_undeclared_block_type_is_held_to_the_measure(): void
    {
        app(Vela::class)->registerBlock('plugin-widget', ['label' => 'Widget', 'view' => 'vela::public.pages.blocks.text']);

        $this->assertFalse(app(Vela::class)->blocks()->bleeds('plugin-widget'));
    }
}
