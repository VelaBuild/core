<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The public side of the palette: what a row actually renders.
 *
 * A row's colour used to be the hex it was on the day it was chosen. Change
 * the site's theme and the pages somebody had bothered to style by hand were
 * the ones that broke — white text on a white ground, and nothing to do but
 * open all forty and repick.
 */
class RowColoursFollowTheThemeTest extends PackageTestCase
{
    use RefreshDatabase;

    private function render(array $rowAttributes): string
    {
        $page = Page::create([
            'title'  => 'Home',
            'slug'   => 'home-' . uniqid(),
            'status' => 'published',
            'locale' => config('vela.primary_language', 'en'),
        ]);

        $row = PageRow::create(array_merge(['page_id' => $page->id, 'order_column' => 0], $rowAttributes));

        PageBlock::create([
            'page_row_id'  => $row->id,
            'type'         => 'text',
            'content'      => ['blocks' => []],
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
        ]);

        return view('vela::templates._partials.page-rows', ['page' => $page->fresh()->load('rows.blocks')])->render();
    }

    public function test_a_palette_colour_renders_as_the_theme_property(): void
    {
        $html = $this->render([
            'background_color' => 'token:surface',
            'text_color'       => 'token:ink',
        ]);

        $this->assertStringContainsString('background-color:var(--vela-surface, #f9fafb)', $html);
        $this->assertStringContainsString('color:var(--vela-ink, #1f2937)', $html);
        // Blocks that paint their own container read this to know the author
        // overrode the colour, so it has to carry the resolved value too.
        $this->assertStringContainsString('--vela-text-color:var(--vela-ink, #1f2937)', $html);
    }

    public function test_a_hex_chosen_before_the_palette_existed_still_renders(): void
    {
        $html = $this->render(['background_color' => '#123456']);

        $this->assertStringContainsString('background-color:#123456', $html);
    }

    public function test_a_row_cannot_carry_its_own_declarations_into_the_style_attribute(): void
    {
        $html = $this->render([
            'background_color' => 'red; position: fixed; inset: 0; z-index: 9999',
            'text_alignment'   => 'center; background: url(https://example.com/x)',
            'padding'          => '20px; display: none',
        ]);

        $this->assertStringNotContainsString('position: fixed', $html);
        $this->assertStringNotContainsString('example.com', $html);
        $this->assertStringNotContainsString('display: none', $html);
    }

    public function test_the_chatbot_is_judged_on_palette_names_too(): void
    {
        $page = Page::create([
            'title'  => 'Contrast',
            'slug'   => 'contrast-' . uniqid(),
            'status' => 'draft',
            'locale' => 'en',
        ]);

        // The contrast guard read hex only, so the moment the chatbot started
        // naming palette colours it stopped looking at these pairs at all.
        $unreadable = (new \VelaBuild\Core\Services\AiChat\Tools\AddRowTool())->execute([
            'page_id'          => $page->id,
            'background_color' => 'token:ink',
            'text_color'       => 'token:ink-soft',
        ]);

        $this->assertArrayHasKey('error', $unreadable);
        // Reported back in the words the model used, not the hex behind them.
        $this->assertStringContainsString('token:ink', $unreadable['error']);

        $fine = (new \VelaBuild\Core\Services\AiChat\Tools\AddRowTool())->execute([
            'page_id'          => $page->id,
            'background_color' => 'token:accent',
            'text_color'       => 'token:accent-ink',
        ]);

        $this->assertArrayNotHasKey('error', $fine);
    }

    public function test_none_still_means_none(): void
    {
        // "0" is a real answer, and the truthiness check this replaced read it
        // as false, so choosing None left the template's 20px where it was.
        $html = $this->render(['padding' => '0']);

        $this->assertStringContainsString('padding-top:0;padding-bottom:0;', $html);
    }
}
