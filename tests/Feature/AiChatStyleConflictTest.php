<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\Tools\GetCustomCssTool;
use VelaBuild\Core\Services\AiChat\Tools\GetPageBlocksTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Asked why a hero image will not appear, the chatbot used to read the block,
 * see the image URL sitting right there, and report that the page was fine —
 * because the rule hiding it lives in the page's Advanced settings CSS, which
 * no read tool returned. Both read tools now carry that CSS and the conflict.
 */
class AiChatStyleConflictTest extends PackageTestCase
{
    private function makePage(string $css): Page
    {
        $page = Page::create([
            'title'      => 'Home Fixture',
            'slug'       => 'style-conflict-fixture',
            'status'     => 'published',
            'locale'     => 'en',
            'custom_css' => $css,
        ]);

        $row = PageRow::create(['page_id' => $page->id, 'order_column' => 0]);

        PageBlock::create([
            'page_row_id'      => $row->id,
            'type'             => 'hero',
            'column_index'     => 0,
            'column_width'     => 12,
            'order_column'     => 0,
            'content'          => ['title' => 'Welcome'],
            'settings'         => ['background_overlay' => 'rgba(0,0,0,0.4)'],
            'background_image' => 'https://site.test/hero.jpg',
        ]);

        return $page;
    }

    public function test_get_page_blocks_returns_the_advanced_settings_css(): void
    {
        $page = $this->makePage('#block-1 .block-hero { color: #fff; }');

        $result = (new GetPageBlocksTool())->execute(['page_id' => $page->id]);

        $this->assertSame('#block-1 .block-hero { color: #fff; }', $result['custom_css']['page']);
        $this->assertArrayHasKey('site', $result['custom_css']);
    }

    public function test_an_opaque_rule_over_a_hero_image_is_reported_as_a_conflict(): void
    {
        $page = $this->makePage('');
        $blockId = PageBlock::where('page_row_id', $page->rows->first()->id)->first()->id;
        $page->update(['custom_css' => "#block-{$blockId} .block-hero { background-color: #0f172a; color: #ffffff; }"]);

        $result = (new GetPageBlocksTool())->execute(['page_id' => $page->id]);

        $this->assertCount(1, $result['style_conflicts']);
        $this->assertSame("#block-{$blockId}", $result['style_conflicts'][0]['hides']);
        $this->assertStringContainsString('background-color: #0f172a', $result['style_conflicts'][0]['declaration']);
        $this->assertStringContainsString('opaque', $result['style_conflicts'][0]['problem']);
        $this->assertStringContainsString('hiding', $result['style_conflicts_note']);
    }

    public function test_get_custom_css_reports_the_same_conflict_for_the_page_scope(): void
    {
        $page = $this->makePage('');
        $blockId = PageBlock::where('page_row_id', $page->rows->first()->id)->first()->id;
        $page->update(['custom_css' => "#block-{$blockId} .block-hero { background: #111; }"]);

        $result = (new GetCustomCssTool())->execute(['scope' => 'page', 'page_id' => $page->id]);

        $this->assertCount(1, $result['style_conflicts']);
        $this->assertSame("#block-{$blockId}", $result['style_conflicts'][0]['hides']);
    }

    public function test_a_clean_page_reports_no_conflict(): void
    {
        $page = $this->makePage('#block-1 { background-color: #0f172a; }');

        $result = (new GetPageBlocksTool())->execute(['page_id' => $page->id]);

        $this->assertSame([], $result['style_conflicts']);
        $this->assertStringContainsString('No custom CSS rule', $result['style_conflicts_note']);
    }
}
