<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\Tools\UpdateCustomCssTool;
use VelaBuild\Core\Services\AiChat\Tools\UpdateRowTool;
use VelaBuild\Core\Services\AiChat\Tools\UpdateTemplateColorsTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Asked to make a site look like another one, the chatbot copies what it
 * finds there. Both of these came out of that: a section left with the white
 * text chosen for a dark background after the background was lightened, and
 * font rules naming custom properties that mean something on the site they
 * were lifted from and nothing here.
 */
class AiChatDesignGuardsTest extends PackageTestCase
{
    private function darkRow(): PageRow
    {
        $page = Page::create([
            'title'  => 'Home',
            'slug'   => 'home',
            'status' => 'published',
            'locale' => 'en',
        ]);

        return PageRow::create([
            'page_id'          => $page->id,
            'order_column'     => 0,
            'background_color' => '#1e3a5f',
            'text_color'       => '#ffffff',
        ]);
    }

    public function test_lightening_a_background_without_the_text_is_refused(): void
    {
        $row = $this->darkRow();

        $result = (new UpdateRowTool())->execute([
            'row_id'           => $row->id,
            'background_color' => '#ffffff',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(1.0, $result['contrast_ratio']);
        $this->assertSame('#1e3a5f', $row->fresh()->background_color, 'the unreadable state must not be saved');
    }

    public function test_sending_both_halves_of_the_pair_is_accepted(): void
    {
        $row = $this->darkRow();

        $result = (new UpdateRowTool())->execute([
            'row_id'           => $row->id,
            'background_color' => '#ffffff',
            'text_color'       => '#0f172a',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('#ffffff', $row->fresh()->background_color);
    }

    public function test_a_section_with_a_background_image_is_left_alone(): void
    {
        // The picture carries its own contrast; the two colours are not the
        // whole story there.
        $row = $this->darkRow();

        $result = (new UpdateRowTool())->execute([
            'row_id'           => $row->id,
            'background_color' => '#ffffff',
            'background_image' => 'https://example.com/hero.jpg',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_a_site_colour_is_written_where_the_page_reads_it(): void
    {
        // It used to be stored under css_*, a namespace nothing reads: the
        // value was saved, the tool reported success, and the site was
        // unchanged. "brand" is what the model calls it; primary is the role.
        $result = (new UpdateTemplateColorsTool())->execute(['colors' => ['brand' => '#f59e0b']]);

        $this->assertTrue($result['success']);
        $this->assertSame('#f59e0b', VelaConfig::where('key', 'theme_primary_color')->value('value'));
        $this->assertNull(VelaConfig::where('key', 'css_brand')->value('value'));
    }

    public function test_a_template_that_ignores_site_colours_says_so(): void
    {
        config(['vela.template.active' => 'default']);

        $result = (new UpdateTemplateColorsTool())->execute(['colors' => ['primary' => '#f59e0b']]);

        $this->assertStringContainsString('does not read these colours', $result['warning']);
    }

    public function test_a_template_that_follows_them_needs_no_warning(): void
    {
        config(['vela.template.active' => 'minimal']);

        $result = (new UpdateTemplateColorsTool())->execute(['colors' => ['primary' => '#f59e0b']]);

        $this->assertArrayNotHasKey('warning', $result);
    }

    public function test_a_colour_this_tool_cannot_set_is_refused(): void
    {
        $result = (new UpdateTemplateColorsTool())->execute(['colors' => ['sidebar' => '#123456']]);

        $this->assertSame(['primary', 'secondary', 'background'], $result['valid_colors']);
    }

    public function test_css_reading_a_property_this_site_never_sets_is_refused(): void
    {
        $result = (new UpdateCustomCssTool())->execute([
            'scope' => 'site',
            'css'   => "body { font-family: var(--font-inter), system-ui; }",
        ]);

        $this->assertContains('--font-inter', $result['undefined_variables']);
    }

    public function test_a_property_defined_in_the_same_stylesheet_is_accepted(): void
    {
        $result = (new UpdateCustomCssTool())->execute([
            'scope' => 'site',
            'css'   => ":root { --brand-font: Georgia, serif; }\n.block-hero-title { font-family: var(--brand-font); }",
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_the_sites_own_block_properties_are_accepted(): void
    {
        $result = (new UpdateCustomCssTool())->execute([
            'scope' => 'site',
            'css'   => '.block-hero-title { color: var(--block-accent); }',
        ]);

        $this->assertTrue($result['success']);
    }
}
