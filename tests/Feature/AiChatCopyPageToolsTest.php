<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Services\AiChat\Tools\BrowseUrlTool;
use VelaBuild\Core\Services\AiChat\Tools\DownloadImageTool;
use VelaBuild\Core\Services\AiChat\Tools\ScreenshotUrlTool;
use VelaBuild\Core\Services\BrowserRenderingService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Copying a page used to come out coarse: the model was handed truncated
 * markup, never saw the page, and had no count to check its rebuild against.
 * These cover the three tools that changed.
 */
class AiChatCopyPageToolsTest extends PackageTestCase
{
    private function remotePage(): string
    {
        return '<html><body><main>'
            . '<section class="hero"><h1>Ship faster</h1><p>' . str_repeat('Launch in days not quarters. ', 3) . '</p>'
            . '<a class="btn" href="/signup">Start free</a><img src="/img/hero.png" alt="App"></section>'
            . '<section class="features"><h2>Why us</h2><div class="grid">'
            . '<div class="card"><h3>Fast</h3><p>Deploy in seconds.</p></div>'
            . '<div class="card"><h3>Safe</h3><p>Backed up always.</p></div>'
            . '<div class="card"><h3>Simple</h3><p>Nothing to configure.</p></div>'
            . '</div></section>'
            . '</main><script>var tracking=1;</script></body></html>';
    }

    public function test_sections_action_outlines_the_page_without_a_browser(): void
    {
        Http::fake(['acme.example/*' => Http::response($this->remotePage(), 200, ['content-type' => 'text/html'])]);

        $result = (new BrowseUrlTool())->execute(['url' => 'https://acme.example/', 'action' => 'sections']);

        $this->assertTrue($result['success']);
        $this->assertSame('http_fetch', $result['method']);
        $this->assertSame(2, $result['section_count']);
        $this->assertSame('Ship faster', $result['sections'][0]['heading']);
        $this->assertSame('hero', $result['sections'][0]['suggested_block']);
        $this->assertSame(3, $result['sections'][1]['repeated_items']['count']);
        // The count the finished rebuild is checked against.
        $this->assertStringContainsString('2 sections', $result['next_step']);
        $this->assertStringContainsString('get_page_blocks', $result['next_step']);
    }

    public function test_html_action_strips_scripts_before_truncating(): void
    {
        Http::fake(['acme.example/*' => Http::response($this->remotePage(), 200, ['content-type' => 'text/html'])]);

        $result = (new BrowseUrlTool())->execute(['url' => 'https://acme.example/', 'action' => 'html']);

        $this->assertStringNotContainsString('var tracking', $result['html']);
        $this->assertStringContainsString('Ship faster', $result['html']);
        $this->assertStringContainsString('script/style/svg/comments removed', $result['stripped']);
    }

    public function test_html_action_can_scope_to_one_element(): void
    {
        Http::fake(['acme.example/*' => Http::response($this->remotePage(), 200, ['content-type' => 'text/html'])]);

        $result = (new BrowseUrlTool())->execute([
            'url' => 'https://acme.example/',
            'action' => 'html',
            'selector' => '.features',
        ]);

        $this->assertStringContainsString('Why us', $result['html']);
        $this->assertStringNotContainsString('Ship faster', $result['html']);
    }

    public function test_unknown_actions_without_a_browser_name_the_ones_that_still_work(): void
    {
        $result = (new BrowseUrlTool())->execute(['url' => 'https://acme.example/', 'action' => 'pdf']);

        $this->assertStringContainsString('sections', $result['error']);
    }

    public function test_images_download_in_one_batch_and_report_each_failure(): void
    {
        Storage::fake();
        Http::fake([
            'cdn.acme.example/ok-*' => Http::response('binary-bytes', 200, ['content-type' => 'image/png']),
            'cdn.acme.example/gone*' => Http::response('', 404),
        ]);

        $result = (new DownloadImageTool())->execute(['urls' => [
            'https://cdn.acme.example/ok-1.png',
            'https://cdn.acme.example/ok-2.png',
            'https://cdn.acme.example/gone.png',
            // A repeat of one already taken must not be fetched twice.
            'https://cdn.acme.example/ok-1.png',
        ]]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['saved_count']);
        $this->assertCount(1, $result['failed']);
        $this->assertStringContainsString('404', $result['failed'][0]['error']);
    }

    public function test_a_screenshot_is_handed_back_as_a_picture_not_only_a_path(): void
    {
        Storage::fake();
        $png = base64_encode($this->onePixelPng());

        $this->app->bind(BrowserRenderingService::class, fn () => new class ($png) extends BrowserRenderingService {
            public function __construct(private string $png)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function screenshot(string $url, array $options = []): ?string
            {
                return $this->png;
            }
        });

        $result = (new ScreenshotUrlTool())->execute(['url' => 'https://acme.example/']);

        $this->assertTrue($result['success']);
        $this->assertSame($png, $result['_images'][0]['base64']);
        $this->assertSame('image/png', $result['_images'][0]['media_type']);
        $this->assertStringContainsString('acme.example', $result['_images'][0]['label']);
    }

    private function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
