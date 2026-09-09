<?php

namespace VelaBuild\Core\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * The catch-all page route refused every slug that BEGAN with a route name.
 *
 * The exclusion is meant to keep pages off the paths that belong to routes of
 * their own — /admin, /login, /home — and the lookahead it used had no anchor,
 * so it read as "must not start with". Vela makes pages called `home-…`
 * itself: "install as a new page" wrote home-1 and the page it had just made
 * answered 404, and every homepage parked so it could be put back was a page
 * nobody could open to see what was in it.
 */
class PageSlugsThatBeginWithARouteNameTest extends PackageTestCase
{
    private function page(string $slug): Page
    {
        $page = Page::create([
            'title' => 'Kept',
            'slug' => $slug,
            'locale' => 'en',
            'status' => 'published',
        ]);

        $row = $page->rows()->create(['name' => 'Body', 'width' => 'contained', 'order_column' => 0]);
        $row->blocks()->create([
            'type' => 'text',
            'content' => ['text' => 'The homepage that was replaced'],
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
        ]);

        return $page;
    }

    public static function slugsThatMerelyBeginWithOne(): array
    {
        return [
            ['home-2026-09-09-114900'],
            ['home-1'],
            ['login-help'],
            ['admin-guide'],
            ['registered-charity'],
            ['profile-of-a-founder'],
        ];
    }

    #[DataProvider('slugsThatMerelyBeginWithOne')]
    public function test_a_page_whose_slug_starts_with_a_route_name_can_be_opened(string $slug): void
    {
        $this->page($slug);

        $this->get('/' . $slug)->assertOk();
    }

    /**
     * And the names themselves still belong to their own routes.
     *
     * /home is not a page even when a page is sitting under that slug — the
     * homepage is served from "/" and the exclusion is what keeps the two
     * from being two ways to the same thing.
     */
    public function test_the_route_names_themselves_are_still_not_pages(): void
    {
        $this->page('home');

        $response = $this->get('/home');

        // Whatever answers it — a redirect to "/" here — it is not the page
        // route serving a second copy of the homepage under a second address.
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('The homepage that was replaced', $response->getContent());
    }
}
