<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\AiChat\StyleConflictDetector;
use VelaBuild\Core\Tests\PackageTestCase;

class StyleConflictDetectorTest extends PackageTestCase
{
    private array $targets = ['#block-1' => 'https://site.test/hero.jpg'];

    private function detect(string $css): array
    {
        return StyleConflictDetector::detect($this->targets, ['page custom CSS' => $css]);
    }

    public function test_an_opaque_child_background_is_reported(): void
    {
        $found = $this->detect('#block-1 .block-hero { background-color: #0f172a; color: #fff; }');

        $this->assertCount(1, $found);
        $this->assertSame('#block-1 .block-hero', $found[0]['selector']);
        $this->assertSame('#block-1', $found[0]['hides']);
        $this->assertStringContainsString('background-color: #0f172a', $found[0]['declaration']);
        $this->assertSame('page custom CSS', $found[0]['origin']);
    }

    public function test_a_colour_on_the_wrapper_itself_is_a_fallback_not_a_cover_up(): void
    {
        // Same element as the image: the colour paints beneath it.
        $this->assertSame([], $this->detect('#block-1 { background-color: #0f172a; }'));
    }

    public function test_translucent_and_transparent_children_are_left_alone(): void
    {
        $this->assertSame([], $this->detect('#block-1 .block-hero-overlay { background: rgba(0,0,0,0.4); }'));
        $this->assertSame([], $this->detect('#block-1 .block-hero { background-color: transparent; }'));
        $this->assertSame([], $this->detect('#block-1 .block-hero { background: #0f172a80; }'));
    }

    public function test_a_child_that_paints_its_own_image_or_gradient_is_not_a_conflict(): void
    {
        $this->assertSame([], $this->detect('#block-1 .block-hero { background: url(/other.jpg) center/cover; }'));
        $this->assertSame([], $this->detect('#block-1 .block-hero { background: linear-gradient(#000, #333); }'));
    }

    public function test_blocks_without_a_background_image_are_never_flagged(): void
    {
        $this->assertSame([], StyleConflictDetector::detect([], ['page custom CSS' => '#block-1 .x { background: #000; }']));
        $this->assertSame([], $this->detect('#block-9 .block-hero { background-color: #000; }'));
    }

    public function test_an_id_prefix_of_a_longer_id_does_not_match(): void
    {
        $this->assertSame([], $this->detect('#block-12 .block-hero { background-color: #000; }'));
    }

    public function test_each_selector_in_a_group_is_checked(): void
    {
        $found = $this->detect('#block-2 .block-text, #block-1 .block-hero { background-color: #ffffff; }');

        $this->assertCount(1, $found);
        $this->assertSame('#block-1 .block-hero', $found[0]['selector']);
    }

    public function test_rules_inside_a_media_query_still_count(): void
    {
        $found = $this->detect('@media (max-width: 768px) { #block-1 .block-hero { background-color: #111; } }');

        $this->assertCount(1, $found);
    }

    public function test_the_sheet_a_rule_came_from_is_reported(): void
    {
        $found = StyleConflictDetector::detect($this->targets, [
            'page custom CSS' => '',
            'site-wide custom CSS' => '#block-1 .block-hero { background: #000; }',
        ]);

        $this->assertCount(1, $found);
        $this->assertSame('site-wide custom CSS', $found[0]['origin']);
    }
}
