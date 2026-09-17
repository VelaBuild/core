<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What an app download block puts on the page. Its badges link to the block's
 * own ios_url / android_url, or else the app_ios_url / app_android_url settings.
 */
class AppDownloadBlockRenderTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('import-content-ran:' . now()->toDateString(), true, now()->endOfDay());
    }

    private function render(array $settings = [], array $content = ['heading' => 'Get the app']): string
    {
        $slug = 'app-check-' . uniqid();
        $page = Page::create(['title' => 'App', 'slug' => $slug, 'locale' => 'en', 'status' => 'published']);
        $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0])->blocks()->create([
            'type' => 'app_download', 'content' => $content, 'settings' => $settings,
            'column_index' => 0, 'column_width' => 12, 'order_column' => 0,
        ]);

        return $this->get('/' . $slug)->assertOk()->getContent();
    }

    private function links(?string $ios, ?string $android): void
    {
        foreach (['app_ios_url' => $ios, 'app_android_url' => $android] as $key => $value) {
            VelaConfig::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public function test_nothing_is_drawn_until_a_store_link_is_set(): void
    {
        $this->links(null, null);

        $this->assertStringNotContainsString('block-app-download', $this->render());
    }

    private function wrapper(string $html): string
    {
        preg_match('/<div class="block-app-download[ "][^>]*>/', $html, $tag);

        return $tag[0] ?? '';
    }

    public function test_a_block_saved_before_there_were_choices_looks_as_it_did(): void
    {
        $this->links('https://apps.apple.com/app/id1', null);

        $html = $this->render(['text_alignment' => 'center'], ['heading' => 'Get the app', 'description' => 'On your phone']);

        $this->assertSame('<div class="block-app-download block-app-download--stacked block-app-download--normal block-app-download--align-center block-app-download--badge-dark" style="text-align:center;">', $this->wrapper($html));
        $this->assertStringContainsString('<div class="block-app-download-badges" style="justify-content:center;">', $html);
        $this->assertStringNotContainsString('block-app-download-media', $html);
        $this->assertStringNotContainsString('block-app-download-eyebrow', $html);
    }

    public function test_css_in_a_setting_does_not_reach_the_style(): void
    {
        // The alignment once covered the whole page in red.
        $this->links('https://apps.apple.com/app/id1', null);

        $html = $this->render([
            'text_alignment' => 'center;background:red;position:fixed;inset:0', 'background' => 'red;position:fixed',
            'layout' => '"><x', 'size' => 'huge', 'badge_style' => 'url(x)', 'image_side' => 'top',
        ]);
        $tag = $this->wrapper($html);

        $this->assertStringNotContainsString('position:fixed', $tag);
        $this->assertStringNotContainsString('url(', $tag);
        $this->assertSame('<div class="block-app-download block-app-download--stacked block-app-download--normal block-app-download--align-center block-app-download--badge-dark" style="text-align:center;">', $tag);
        $this->assertStringContainsString('<div class="block-app-download-badges" style="justify-content:center;">', $html);
    }

    public function test_a_blocks_own_store_link_wins_and_needs_no_setting(): void
    {
        $this->links(null, null);

        $html = $this->render([], ['heading' => 'Hi', 'ios_url' => 'https://apps.apple.com/app/own', 'android_url' => '']);

        $this->assertStringContainsString('<a href="https://apps.apple.com/app/own"', $html);
        $this->assertStringNotContainsString('block-app-download-badge--android', $html);

        $this->links('https://apps.apple.com/app/site', 'https://play.google.com/store/apps/details?id=site');
        $html = $this->render([], ['heading' => 'Hi', 'ios_url' => 'https://apps.apple.com/app/own']);

        $this->assertStringContainsString('href="https://apps.apple.com/app/own"', $html);
        $this->assertStringNotContainsString('app/site', $html);
        // An empty own link falls back to the site's.
        $this->assertStringContainsString('href="https://play.google.com/store/apps/details?id=site"', $html);
    }

    public function test_a_store_link_that_is_not_a_web_address_is_not_used(): void
    {
        $this->links(null, 'javascript:alert(1)');

        $this->assertStringNotContainsString('block-app-download', $this->render([], ['heading' => 'Hi', 'ios_url' => 'javascript:alert(2)']));

        $this->links('https://apps.apple.com/app/site', null);
        $html = $this->render([], ['heading' => 'Hi', 'ios_url' => 'data:text/html,x', 'android_url' => ' JavaScript:alert(3)']);

        $this->assertStringContainsString('href="https://apps.apple.com/app/site"', $html);
        $this->assertStringNotContainsString('alert', $html);
        $this->assertStringNotContainsString('data:text', $html);
    }

    public function test_the_words_picture_and_look_that_were_chosen(): void
    {
        $this->links('https://apps.apple.com/app/id1', null);

        $html = $this->render(
            ['layout' => 'split', 'size' => 'large', 'text_alignment' => 'left', 'background' => '#0f172a', 'badge_style' => 'outline', 'image_side' => 'left'],
            ['eyebrow' => 'New <b>', 'heading' => 'Take us with you', 'note' => 'Free · No ads', 'image' => 'https://example.com/phone.png', 'image_alt' => 'The booking "screen"']
        );

        $this->assertSame('<div class="block-app-download block-app-download--split block-app-download--large block-app-download--align-left block-app-download--badge-outline has-app-image block-app-download--image-left has-app-bg" style="text-align:left;--app-bg:#0f172a;--app-ink:#ffffff;">', $this->wrapper($html));
        $this->assertStringContainsString('<div class="block-app-download-eyebrow">New &lt;b&gt;</div>', $html);
        $this->assertStringContainsString('<div class="block-app-download-note">Free · No ads</div>', $html);
        $this->assertMatchesRegularExpression('#<div class="block-app-download-media">\s*<img[^>]*alt="The booking &quot;screen&quot;"#', $html);
    }

    public function test_a_token_background_is_kept_and_a_picture_side_only_comes_with_a_picture(): void
    {
        $this->links('https://apps.apple.com/app/id1', null);

        $tag = $this->wrapper($this->render(['layout' => 'card', 'background' => 'token:accent', 'image_side' => 'left']));

        $this->assertStringContainsString('block-app-download--card', $tag);
        $this->assertStringContainsString('has-app-bg', $tag);
        $this->assertStringContainsString('--app-bg:var(', $tag);
        $this->assertStringNotContainsString('image-left', $tag);
    }

    public function test_badges_carry_their_own_icons_and_only_the_stores_set(): void
    {
        $this->links(null, 'https://play.google.com/store/apps/details?id=com.example');

        $html = $this->render(['text_alignment' => 'right']);

        $this->assertStringNotContainsString('block-app-download-badge--ios', $html);
        $this->assertMatchesRegularExpression('/<a href="https:\/\/play\.google\.com\/store\/apps\/details\?id=com\.example" target="_blank" rel="noopener noreferrer" class="block-app-download-badge block-app-download-badge--android">\s*.*?<svg class="block-app-download-icon"/s', $html);
        $this->assertStringContainsString('style="justify-content:flex-end;"', $html);
        // No icon font is relied on any more.
        $this->assertStringNotContainsString('fab fa-google-play', $html);
    }

    public function test_the_heading_is_text(): void
    {
        $this->links('https://apps.apple.com/app/id1', null);

        $this->assertStringContainsString('Get the &lt;b&gt;app&lt;/b&gt;', $this->render([], ['heading' => 'Get the <b>app</b>']));
    }
}
