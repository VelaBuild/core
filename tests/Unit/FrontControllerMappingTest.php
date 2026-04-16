<?php

namespace VelaBuild\Core\Tests\Unit;

use VelaBuild\Core\Tests\TestCase;

class FrontControllerMappingTest extends TestCase
{
    /**
     * Replicate the front-controller URL-to-filepath mapping logic from public/index.php.
     * Returns the expected file path, or null if the request should be excluded/passed through.
     */
    private function mapUriToStaticFile(string $uri, string $basePath): ?string
    {
        $uri = rawurldecode(strtok($uri, '?'));
        $uri = rtrim($uri, '/') ?: '/';

        $exclude = [
            'admin', 'vela', 'login', 'logout', 'register', 'password',
            'home', 'profile', 'two-factor', 'imgp', 'imgr', 'api', 'page-form',
            'storage', 'vendor', 'livewire', 'horizon', 'telescope',
        ];

        $segments = explode('/', ltrim($uri, '/'));
        $first = $segments[0] ?? '';

        if (in_array($first, $exclude, true)) {
            return null;
        }

        $locale = null;
        $slugSegments = $segments;

        if (preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $first)) {
            $locale = $first;
            array_shift($slugSegments);
        }

        $path = implode('/', $slugSegments);

        if ($uri === '/') {
            return $basePath . '/home/index.html';
        } elseif ($locale !== null && $path === '') {
            return $basePath . '/home/translations/' . $locale . '.html';
        } elseif ($locale !== null) {
            if (strpos($path, 'posts/') === 0) {
                $slug = substr($path, 6);
                return $basePath . '/posts/' . $slug . '/translations/' . $locale . '.html';
            } elseif ($path === 'posts') {
                return $basePath . '/posts/translations/' . $locale . '.html';
            } elseif (strpos($path, 'categories/') === 0) {
                $slug = substr($path, 11);
                return $basePath . '/categories/' . $slug . '/translations/' . $locale . '.html';
            } elseif ($path === 'categories') {
                return $basePath . '/categories/translations/' . $locale . '.html';
            } else {
                return $basePath . '/pages/' . $path . '/translations/' . $locale . '.html';
            }
        } elseif ($path === 'posts') {
            return $basePath . '/posts/index.html';
        } elseif (strpos($path, 'posts/') === 0) {
            $slug = substr($path, 6);
            return $basePath . '/posts/' . $slug . '/index.html';
        } elseif ($path === 'categories') {
            return $basePath . '/categories/index.html';
        } elseif (strpos($path, 'categories/') === 0) {
            $slug = substr($path, 11);
            return $basePath . '/categories/' . $slug . '/index.html';
        } else {
            return $basePath . '/pages/' . $path . '/index.html';
        }
    }

    public function test_homepage_maps_to_home_index(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/home/index.html';

        $result = $this->mapUriToStaticFile('/', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_page_slug_maps_to_pages_dir(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/pages/about-us/index.html';

        $result = $this->mapUriToStaticFile('/about-us', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_post_slug_maps_to_posts_dir(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/posts/my-post/index.html';

        $result = $this->mapUriToStaticFile('/posts/my-post', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_posts_index_maps_correctly(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/posts/index.html';

        $result = $this->mapUriToStaticFile('/posts', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_categories_index_maps_correctly(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/categories/index.html';

        $result = $this->mapUriToStaticFile('/categories', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_category_slug_maps_to_categories_dir(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/categories/travel/index.html';

        $result = $this->mapUriToStaticFile('/categories/travel', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_locale_prefix_maps_to_translation(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/pages/about-us/translations/th.html';

        $result = $this->mapUriToStaticFile('/th/about-us', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_locale_only_maps_to_home_translation(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/home/translations/th.html';

        $result = $this->mapUriToStaticFile('/th', $basePath);

        // '/th' with no sub-path: locale prefix with empty path → home translation
        $this->assertSame($expected, $result);
    }

    public function test_locale_prefixed_post_maps_to_post_translation(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/posts/my-article/translations/th.html';

        $result = $this->mapUriToStaticFile('/th/posts/my-article', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_admin_path_not_intercepted(): void
    {
        $basePath = '/tmp/vela-fc-test';

        $result = $this->mapUriToStaticFile('/admin', $basePath);

        $this->assertNull($result, '/admin should not be mapped to a static file');
    }

    public function test_login_path_not_intercepted(): void
    {
        $basePath = '/tmp/vela-fc-test';

        $result = $this->mapUriToStaticFile('/login', $basePath);

        $this->assertNull($result, '/login should not be mapped to a static file');
    }

    public function test_api_path_not_intercepted(): void
    {
        $basePath = '/tmp/vela-fc-test';

        $result = $this->mapUriToStaticFile('/api/csrf-token', $basePath);

        $this->assertNull($result, '/api paths should not be mapped to static files');
    }

    public function test_imgp_path_not_intercepted(): void
    {
        $basePath = '/tmp/vela-fc-test';

        $result = $this->mapUriToStaticFile('/imgp/someconfig', $basePath);

        $this->assertNull($result, '/imgp paths should not be mapped to static files');
    }

    public function test_query_string_stripped_before_mapping(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/pages/about-us/index.html';

        $result = $this->mapUriToStaticFile('/about-us?foo=bar', $basePath);

        $this->assertSame($expected, $result);
    }

    public function test_trailing_slash_normalized(): void
    {
        $basePath = '/tmp/vela-fc-test';
        $expected = $basePath . '/pages/about-us/index.html';

        $result = $this->mapUriToStaticFile('/about-us/', $basePath);

        $this->assertSame($expected, $result);
    }
}
