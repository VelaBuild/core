<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\MenuItem;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Turning a title somebody remembers into the slug a link needs.
 *
 * Every box in the editor that takes a link took a typed address and nothing
 * else, so pointing a button at a page of your own meant knowing its slug by
 * heart. Getting it wrong is invisible: the editor shows what was typed and
 * the visitor gets a 404.
 */
class LinkSuggestTest extends PackageTestCase
{
    private function signInAsAdmin(): void
    {
        $this->signIn();
        Gate::define('page_access', fn () => true);
        Gate::define('config_access', fn () => true);
    }

    /** @return array<int, array<string, mixed>> */
    private function ask(string $query = ''): array
    {
        $response = $this->getJson(route('vela.admin.link-suggest', ['q' => $query]));
        $response->assertOk();

        return $response->json('results');
    }

    public function test_a_page_is_found_by_its_title_and_comes_back_as_its_address(): void
    {
        $this->signInAsAdmin();

        Page::create(['title' => 'Our story', 'slug' => 'our-story', 'locale' => 'en', 'status' => 'published']);

        $found = collect($this->ask('story'))->firstWhere('label', 'Our story');

        $this->assertNotNull($found, 'a page nobody can find by title is a page nobody can link to');
        $this->assertSame('page', $found['kind']);
        $this->assertStringEndsWith('/our-story', $found['url']);
    }

    public function test_a_page_that_is_not_published_is_offered_but_says_so(): void
    {
        $this->signInAsAdmin();

        Page::create(['title' => 'Pricing', 'slug' => 'pricing', 'locale' => 'en', 'status' => 'draft']);

        $found = collect($this->ask('pricing'))->firstWhere('label', 'Pricing');

        // Linking to a page before publishing it is ordinary; being told it is
        // not live yet is the part that was missing.
        $this->assertNotNull($found);
        $this->assertSame('draft', $found['note']);
    }

    public function test_the_front_page_and_the_listings_are_offered_too(): void
    {
        $this->signInAsAdmin();

        // They have no row anywhere to be found by title, and they are among
        // the likeliest things somebody wants a link to.
        $labels = array_column($this->ask(''), 'label');

        $this->assertContains('Home', $labels);
    }

    public function test_it_is_not_open_to_anybody(): void
    {
        $this->signIn();
        Gate::define('page_access', fn () => false);
        Gate::define('config_access', fn () => false);

        $this->getJson(route('vela.admin.link-suggest', ['q' => 'a']))->assertForbidden();
    }

    public function test_a_category_knows_the_address_it_is_reached_at(): void
    {
        // There is no slug column: CategoryController::show() slugifies every
        // name and compares. Everything else assumed the column existed, so
        // $category->slug was null — and MenuItem::resolveUrl() built the
        // route with it, threw, was caught, and returned "#". Every category
        // menu item on every Vela site pointed at nothing.
        $category = Category::create(['name' => 'Trail Running']);

        $this->assertSame('trail-running', $category->slug);

        $item = new MenuItem(['type' => MenuItem::TYPE_CATEGORY, 'ref_id' => $category->id]);

        $this->assertStringEndsWith('/categories/trail-running', $item->resolveUrl());
        $this->assertNotSame('#', $item->resolveUrl());
    }
}
