<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a contact form block puts on the page for each of its layouts.
 */
class ContactFormLayoutTest extends PackageTestCase
{
    private function render(array $content, array $settings): string
    {
        $slug = 'contact-check-' . Page::count();
        $page = Page::create(['title' => 'Contact', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'contact_form', 'content' => $content, 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    public function test_a_block_saved_before_layouts_existed_is_stacked(): void
    {
        $html = $this->render(['title' => 'Write to us'], []);

        $this->assertStringContainsString('class="block-contact-form block-contact-form--stacked"', $html);
        $this->assertStringNotContainsString('block-contact-form-grid', $html);
        $this->assertStringContainsString('Write to us', $html);
    }

    public function test_an_empty_submit_label_still_gives_the_button_words(): void
    {
        // The registry's default is '' and the view used `??`, which lets an
        // empty string through: a new block's button had nothing on it.
        $html = $this->render([], ['submit_label' => '']);

        $this->assertMatchesRegularExpression('/<button type="submit">\s*\S+/', $html);
    }

    public function test_two_columns_names_its_layout(): void
    {
        $this->assertStringContainsString('block-contact-form--two-column', $this->render([], ['layout' => 'two_column']));
    }

    public function test_card_wraps_the_form_in_a_panel(): void
    {
        $html = $this->render([], ['layout' => 'card']);

        $this->assertStringContainsString('block-contact-form--card', $html);
        $this->assertStringContainsString('block-contact-form-card', $html);
    }

    public function test_split_info_puts_the_details_beside_the_form(): void
    {
        $html = $this->render(
            ['title' => 'Visit us', 'info_address' => "12 Sukhumvit Rd\nBangkok", 'info_phone' => '+66 2 123 4567', 'info_email' => 'hi@example.com'],
            ['layout' => 'split_info', 'aside_position' => 'right']
        );

        $this->assertStringContainsString('block-contact-form--split-info block-contact-form--aside-right', $html);
        $this->assertStringContainsString('href="tel:+6621234567"', $html);
        $this->assertStringContainsString('href="mailto:hi@example.com"', $html);
        $this->assertStringContainsString('12 Sukhumvit Rd<br />', $html);
        // Hours were left empty, so nothing is said about them.
        $this->assertStringNotContainsString('block-contact-form-info-hours', $html);

        // The heading is in the aside, before the form, and only once.
        $this->assertSame(1, substr_count($html, 'Visit us</h2>'));
        $this->assertLessThan(strpos($html, '<form'), strpos($html, 'Visit us</h2>'));
    }

    public function test_a_split_is_drawn_as_chosen_before_anything_is_filled_in(): void
    {
        // It used to fall back to stacked, which looked like the choice had not saved.
        $html = $this->render([], ['layout' => 'split_info']);
        $this->assertStringContainsString('block-contact-form--split-info', $html);
        $this->assertStringNotContainsString('block-contact-form-info"', $html);

        $html = $this->render([], ['layout' => 'split_image']);
        $this->assertStringContainsString('block-contact-form--split-image', $html);
        $this->assertStringNotContainsString('block-contact-form-image', $html);
    }

    public function test_an_unknown_layout_is_stacked(): void
    {
        $html = $this->render([], ['layout' => 'x" onmouseover="alert(1)']);

        $this->assertStringContainsString('block-contact-form--stacked', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
    }

    public function test_the_details_are_escaped(): void
    {
        $html = $this->render(['info_address' => '<script>alert(1)</script>'], ['layout' => 'split_info']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
