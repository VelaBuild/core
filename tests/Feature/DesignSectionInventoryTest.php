<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Everything the QA rounds were told was prose — "the header is wrong",
 * "spacing differs" — and prose cannot say a section is missing, because a page
 * holding five of the design's seven looks perfectly finished on its own. Runs
 * went by with sections never built while rounds were spent on a header, and
 * one added a second hero and reported success.
 *
 * The list read off the design before anything is built is the part of this
 * that can be counted.
 */
class DesignSectionInventoryTest extends PackageTestCase
{
    use RefreshDatabase;

    private function pageWithRows(array $names): array
    {
        $page = Page::create([
            'title' => 'Design preview',
            'slug' => 'design-preview',
            'status' => 'unlisted',
            'locale' => config('vela.primary_language', 'en'),
        ]);

        foreach ($names as $order => $name) {
            $row = $page->rows()->create(['name' => $name, 'width' => 'full', 'order_column' => $order]);
            $row->blocks()->create([
                'type' => 'html',
                'content' => ['html' => '<section><h2>' . $name . '</h2></section>'],
                'column_index' => 0,
                'column_width' => 12,
                'order_column' => 0,
            ]);
        }

        return ['target_page' => ['id' => $page->id]];
    }

    public function test_it_puts_the_designs_sections_beside_the_pages(): void
    {
        $context = $this->pageWithRows(['Hero', 'Features']) + [
            'design_sections' => [
                ['label' => 'Hero', 'what' => 'a headline over a photograph'],
                ['label' => 'Features', 'what' => 'three cards'],
                ['label' => 'Newsletter', 'what' => 'a band with an email field'],
            ],
        ];

        $report = app(DesignBuilderService::class)->sectionsReport($context);

        $this->assertStringContainsString('1. Hero — a headline over a photograph', $report);
        // The one the page has not got has to be visible in the same breath.
        $this->assertStringContainsString('3. Newsletter', $report);
        $this->assertStringContainsString('Hero (a written section)', $report);
        $this->assertStringContainsString('Features (a written section)', $report);
    }

    public function test_it_says_plainly_when_a_section_is_on_the_page_twice(): void
    {
        $context = $this->pageWithRows(['Hero', 'Features', 'hero']) + [
            'design_sections' => [['label' => 'Hero', 'what' => 'a headline']],
        ];

        $report = app(DesignBuilderService::class)->sectionsReport($context);

        $this->assertStringContainsString('more than one section called: hero', $report);
        $this->assertStringContainsString('delete_row', $report);
    }

    public function test_it_stays_out_of_the_way_when_the_design_could_not_be_read(): void
    {
        $context = $this->pageWithRows(['Hero']);

        // No list means no report — a fix round should not be handed an empty
        // checklist to reason from.
        $this->assertSame('', app(DesignBuilderService::class)->sectionsReport($context));
    }
}
