<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What an app download block puts on the page. Its badges come from the
 * app_ios_url / app_android_url settings, not from the block.
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

    public function test_css_in_the_alignment_does_not_reach_the_style(): void
    {
        // This covered the whole page in red.
        $this->links('https://apps.apple.com/app/id1', null);

        $html = $this->render(['text_alignment' => 'center;background:red;position:fixed;inset:0']);

        preg_match_all('/<div class="block-app-download[^"]*"[^>]*>/', $html, $tags);
        $this->assertNotEmpty($tags[0]);
        $this->assertStringNotContainsString('position:fixed', implode('', $tags[0]));
        $this->assertStringContainsString('<div class="block-app-download" style="text-align:center;">', $html);
        $this->assertStringContainsString('<div class="block-app-download-badges" style="justify-content:center;">', $html);
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
