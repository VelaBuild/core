<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a pricing tiers block puts on the page, as the block editor saves it.
 */
class PricingTiersBlockRenderTest extends PackageTestCase
{
    private function render(array $tiers, int $columns = 3, array $settings = []): string
    {
        $slug = 'pricing-check-' . Page::count();
        $page = Page::create(['title' => 'Prices', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'pricing_tiers', 'content' => ['tiers' => $tiers], 'settings' => ['columns' => $columns] + $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    public function test_boxes_left_empty_in_the_editor_still_give_the_button_and_badge_words(): void
    {
        // The editor saves an untouched box as '', which `??` let through.
        $html = $this->render([[
            'name' => 'Standard', 'price' => '1,500', 'price_currency' => '฿', 'period' => '/ visit',
            'cta_text' => '', 'cta_url' => '', 'featured' => true, 'badge' => '', 'features' => [],
        ]]);

        $this->assertStringContainsString('<span class="block-pricing-tier-badge">Most popular</span>', $html);
        $this->assertStringContainsString('<a href="#" class="block-pricing-tier-cta">Get started</a>', $html);
    }

    public function test_every_field_the_editor_offers_reaches_the_card(): void
    {
        $html = $this->render([[
            'name' => 'Premium', 'subtitle' => 'For a business', 'description' => 'Ongoing care.',
            'price' => '4,900', 'price_currency' => '฿', 'period' => '/ month', 'price_note' => 'Billed yearly',
            'features_cap' => 'Everything in Standard, plus',
            'features' => ['Priority callout', ['text' => 'Weekend visits', 'muted' => true]],
            'cta_text' => 'Talk to us', 'cta_url' => '/contact-us', 'featured' => true, 'badge' => 'Best value',
        ]]);

        foreach (['Best value', 'For a business', 'Billed yearly', 'Everything in Standard, plus', 'Talk to us'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('<li class="is-muted">Weekend visits</li>', $html);
    }

    public function test_never_more_columns_than_plans(): void
    {
        // Four across with three plans left an empty fourth column.
        $plan = ['name' => 'Plan', 'price' => '1', 'features' => []];

        $this->assertStringContainsString('style="--tier-cols: 3;"', $this->render([$plan, $plan, $plan], 4));
        $this->assertStringContainsString('style="--tier-cols: 4;"', $this->render([$plan, $plan, $plan, $plan, $plan], 4));
        $this->assertStringContainsString('style="--tier-cols: 4;"', $this->render([$plan, $plan, $plan, $plan], 9));
    }

    public function test_a_block_with_no_look_chosen_follows_the_theme(): void
    {
        $html = $this->render([['name' => 'A', 'price' => '1', 'features' => [], 'featured' => true]]);

        $this->assertStringContainsString('class="block-pricing-tiers block-pricing-tiers--clean" style="--tier-cols: 1;"', $html);
    }

    public function test_chosen_colours_carry_an_ink_that_can_be_read_on_them(): void
    {
        $plan = ['name' => 'A', 'price' => '1', 'features' => []];
        $html = $this->render([$plan, $plan], 3, [
            'card_style' => 'soft', 'card_color' => '#fef3c7', 'button_color' => 'token:accent', 'featured_color' => '#0f172a',
        ]);

        $this->assertStringContainsString('block-pricing-tiers--soft has-card-color has-button-color has-featured-color', $html);
        // A light card gets dark words, a dark highlight white ones.
        $this->assertStringContainsString('--tier-card-bg: #fef3c7; --tier-card-ink: #111827;', $html);
        $this->assertStringContainsString('--tier-featured-bg: #0f172a; --tier-featured-ink: #ffffff;', $html);
        // A theme colour stays a theme colour, with the ink the theme pairs it with.
        $this->assertStringContainsString('--tier-button-bg: var(--vela-primary, #2563eb); --tier-button-ink: var(--vela-primary-ink, #ffffff);', $html);
    }

    public function test_a_colour_that_is_not_one_is_ignored(): void
    {
        $plan = ['name' => 'A', 'price' => '1', 'features' => []];
        $html = $this->render([$plan], 3, ['card_color' => 'red; position: fixed; inset: 0', 'card_style' => '"><script>']);

        $this->assertStringNotContainsString('has-card-color', $html);
        $this->assertStringNotContainsString('position: fixed', $html);
        $this->assertStringContainsString('block-pricing-tiers--clean', $html);
    }
}
