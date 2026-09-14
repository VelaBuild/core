<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a gallery block puts on the page, from its pictures and settings.
 */
class GalleryBlockRenderTest extends PackageTestCase
{
    private function render(array $images, array $settings = []): string
    {
        $slug = 'gallery-check-' . Page::count();
        $page = Page::create(['title' => 'Gallery', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'gallery', 'content' => ['images' => $images], 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function pictures(int $n): array
    {
        return array_map(fn ($i) => ['url' => "https://example.com/{$i}.jpg", 'alt' => "Picture {$i}", 'caption' => ''], range(1, $n));
    }

    public function test_a_gallery_saved_before_layouts_lines_its_pictures_up_as_squares(): void
    {
        // Pictures at their own shapes made rows of mixed heights; that was
        // the complaint, so the old default is not kept.
        $html = $this->render($this->pictures(4), ['columns' => 4, 'gap' => 6]);

        $this->assertStringContainsString('class="block-gallery block-gallery--grid"', $html);
        $this->assertStringContainsString('--gallery-columns: 4; --gallery-columns-small: 2; --gallery-gap: 6px; --gallery-ratio: 1 / 1; --gallery-focus: center;', $html);
        $this->assertStringNotContainsString('x-data', $html);
    }

    public function test_an_apostrophe_in_a_caption_neither_breaks_nor_runs_anything(): void
    {
        $html = $this->render([
            ['url' => 'https://example.com/a.jpg', 'alt' => 'Table', 'caption' => "Chef's table"],
            ['url' => 'https://example.com/b.jpg', 'alt' => 'x', 'caption' => "'); alert(1); ('"],
        ]);

        $this->assertStringContainsString('data-caption="Chef&#039;s table"', $html);
        $this->assertStringNotContainsString("alert(1); ('\"", $html);
        $this->assertStringNotContainsString('@click', $html);
        $this->assertStringContainsString('<figcaption class="gallery-caption">Chef&#039;s table</figcaption>', $html);
    }

    public function test_featured_marks_the_first_picture_and_masonry_crops_nothing(): void
    {
        $html = $this->render($this->pictures(5), ['layout' => 'featured', 'ratio' => '4:3', 'focus' => 'top']);
        $this->assertStringContainsString('block-gallery--featured', $html);
        $this->assertSame(1, substr_count($html, 'gallery-item--featured'));
        $this->assertStringContainsString('--gallery-ratio: 4 / 3; --gallery-focus: top;', $html);

        $this->assertStringContainsString('block-gallery--masonry', $this->render($this->pictures(3), ['layout' => 'masonry']));
    }

    public function test_without_the_lightbox_nothing_is_a_button(): void
    {
        $html = $this->render($this->pictures(2), ['lightbox' => false]);

        $this->assertStringNotContainsString('class="gallery-open"', $html);
        $this->assertStringNotContainsString('data-vela-gallery', $html);
        $this->assertStringNotContainsString('vela-gallery.js', $html);
        $this->assertStringContainsString('class="gallery-frame"', $html);
    }

    public function test_settings_out_of_range_are_held_to_what_the_view_can_draw(): void
    {
        $html = $this->render($this->pictures(2), [
            'layout' => 'x"><script>', 'ratio' => '9:1', 'focus' => 'left', 'columns' => 40, 'gap' => -5,
        ]);

        $this->assertStringContainsString('block-gallery--grid', $html);
        $this->assertStringContainsString('--gallery-columns: 6; --gallery-columns-small: 2; --gallery-gap: 0px; --gallery-ratio: 1 / 1; --gallery-focus: center;', $html);
        $this->assertStringNotContainsString('"><script>', $html);
    }
}
