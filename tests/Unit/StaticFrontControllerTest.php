<?php

namespace VelaBuild\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VelaBuild\Core\Http\StaticFrontController;

/**
 * What public/index.php answers before Laravel boots. Moved here from the
 * starter, where every site carried its own copy; checked against that copy
 * request for request when it moved.
 */
class StaticFrontControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/vela-front-' . bin2hex(random_bytes(4));
        foreach (['home/translations', 'pages/about', 'posts/hello/translations'] as $dir) {
            mkdir("{$this->base}/resources/static/{$dir}", 0755, true);
        }
        mkdir("{$this->base}/storage/app", 0755, true);
        file_put_contents("{$this->base}/resources/static/home/index.html", 'HOME');
        file_put_contents("{$this->base}/resources/static/pages/about/index.html", 'ABOUT');
        file_put_contents("{$this->base}/resources/static/pages/about/index.tags", "page-1 pages\n");
        file_put_contents("{$this->base}/resources/static/posts/hello/translations/de.html", 'HALLO');
        file_put_contents("{$this->base}/resources/secret.html", 'SECRET');
        file_put_contents("{$this->base}/.env", "APP_NAME=\"My Site\"\nVELA_CACHE=true\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function get(string $uri, array $server = [], array $cookies = []): ?array
    {
        return StaticFrontController::resolve($this->base, $server + [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php',
        ], $cookies);
    }

    public function test_published_pages_are_served_from_disk_with_their_cache_tags(): void
    {
        $this->assertStringEndsWith('/home/index.html', $this->get('/')['file']);
        $about = $this->get('/about/?utm=1');
        $this->assertSame(200, $about['status']);
        $this->assertSame('page-1 pages', $about['headers']['Cache-Tag']);
        $this->assertSame('true', $about['headers']['X-Static']);
        $this->assertStringEndsWith('/posts/hello/translations/de.html', $this->get('/de/posts/hello')['file']);
    }

    public function test_slugs_that_look_like_locales_are_pages(): void
    {
        foreach (['ai-chat', 'my-site', 'uk', 'zh-Hans'] as $slug) {
            @mkdir("{$this->base}/resources/static/pages/{$slug}", 0755, true);
            file_put_contents("{$this->base}/resources/static/pages/{$slug}/index.html", strtoupper($slug));
        }
        file_put_contents("{$this->base}/resources/static/home/translations/zh-Hans.html", 'ZH HOME');

        $this->assertStringEndsWith('/pages/ai-chat/index.html', $this->get('/ai-chat')['file']);
        $this->assertStringEndsWith('/pages/my-site/index.html', $this->get('/my-site')['file']);
        // A two-letter page slug with no translation behind it is still a page.
        $this->assertStringEndsWith('/pages/uk/index.html', $this->get('/uk')['file']);
        // Real locales keep working.
        $this->assertStringEndsWith('/home/translations/zh-Hans.html', $this->get('/zh-Hans')['file']);
        $this->assertStringEndsWith('/posts/hello/translations/de.html', $this->get('/de/posts/hello')['file']);
        $this->assertNull($this->get('/de/missing'));
    }

    public function test_static_pages_are_cacheable_at_the_edge(): void
    {
        $cc = $this->get('/about')['headers']['Cache-Control'];
        $this->assertStringContainsString('public', $cc);
        $this->assertStringContainsString('s-maxage=', $cc);
        $this->assertStringContainsString('stale-if-error=', $cc);
    }

    public function test_everything_else_goes_to_laravel(): void
    {
        $this->assertNull($this->get('/admin'));
        $this->assertNull($this->get('/missing'));
        $this->assertNull($this->get('/pages/../../secret'));
        $this->assertNull($this->get('/about', ['REQUEST_METHOD' => 'POST']));

        file_put_contents("{$this->base}/.env", "VELA_CACHE=false\n");
        $this->assertNull($this->get('/about'));
    }

    public function test_a_holding_page_redirects_visitors_but_not_a_signed_in_admin(): void
    {
        file_put_contents("{$this->base}/storage/app/vela-site.php",
            "<?php return ['visibility_holding_page' => true, 'visibility_holding_page_slug' => 'soon'];");

        $this->assertSame(['Location' => '/soon', 'Cache-Control' => 'no-cache, no-store'], $this->get('/about')['headers']);
        $this->assertSame(302, $this->get('/about')['status']);
        $this->assertNull($this->get('/privacy'), 'the privacy page stays reachable');
        $this->assertNull($this->get('/admin'));
        $this->assertNull($this->get('/about', [], ['my_site_session' => 'x']), 'a session goes to Laravel to decide');
    }

    public function test_ai_agents_skip_the_cache_when_x402_is_on(): void
    {
        file_put_contents("{$this->base}/storage/app/vela-site.php",
            "<?php return ['x402_enabled' => true, 'x402_pay_to' => '0xabc'];");

        $this->assertNull($this->get('/about', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; GPTBot/1.1)']));
        $this->assertNotNull($this->get('/about', ['HTTP_USER_AGENT' => 'Mozilla/5.0']));
    }
}
