<?php

namespace VelaBuild\Core\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\MenuItem;
use VelaBuild\Core\Models\Translation;
use VelaBuild\Core\Services\LocalizedLinks;
use VelaBuild\Core\Services\TranslationStatusService;
use VelaBuild\Core\Tests\TestCase;

class LocalizedLinksTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $locales = [];
        foreach (['en' => 'English', 'fr' => 'French', 'th' => 'Thai'] as $code => $name) {
            $locales[$code] = ['name' => $name, 'script' => 'Latn', 'native' => $name, 'regional' => $code];
        }
        config(['laravellocalization.supportedLocales' => $locales, 'vela.primary_language' => 'en', 'app.locale' => 'en', 'app.url' => 'https://cafe.test']);
        app('laravellocalization')->setSupportedLocales($locales);
        app('url')->forceRootUrl('https://cafe.test');
    }

    public function test_links_on_a_translated_page_stay_in_its_language(): void
    {
        $html = '<a href="https://cafe.test/about">About</a>'
            . '<a class="btn" href="/subscriptions?plan=1#top">Plans</a>'
            . '<a href="https://cafe.test/">Home</a>'
            . '<a href="https://cafe.test/fr/gallery">Already French</a>'
            . '<a href="https://cafe.test/vela/login">Admin</a>'
            . '<a href="https://cafe.test/storage/menu.pdf">PDF</a>'
            . '<a href="https://elsewhere.test/about">Other site</a>'
            . '<a href="mailto:hi@cafe.test">Mail</a>'
            . '<a href="#reviews">Anchor</a>';

        $out = LocalizedLinks::rewrite($html, 'fr', 'about');

        $this->assertStringContainsString('href="https://cafe.test/fr/about"', $out);
        $this->assertStringContainsString('href="/fr/subscriptions?plan=1#top"', $out);
        $this->assertStringContainsString('href="https://cafe.test/fr"', $out);
        $this->assertStringContainsString('href="https://cafe.test/fr/gallery"', $out);
        $this->assertStringContainsString('href="https://cafe.test/vela/login"', $out);
        $this->assertStringContainsString('href="https://cafe.test/storage/menu.pdf"', $out);
        $this->assertStringContainsString('href="https://elsewhere.test/about"', $out);
        $this->assertStringContainsString('href="mailto:hi@cafe.test"', $out);
        $this->assertStringContainsString('href="#reviews"', $out);
    }

    public function test_the_language_switcher_leads_to_this_page_in_each_language(): void
    {
        $html = '<a href="https://cafe.test/" data-vela-lang="en">English</a>'
            . '<a href="https://cafe.test/th" data-vela-lang="th">Thai</a>';

        $out = LocalizedLinks::rewrite($html, 'fr', 'posts/cold-brew');

        $this->assertStringContainsString('href="https://cafe.test/posts/cold-brew" data-vela-lang="en"', $out);
        $this->assertStringContainsString('href="https://cafe.test/th/posts/cold-brew" data-vela-lang="th"', $out);
    }

    public function test_the_primary_language_keeps_its_links(): void
    {
        $html = '<a href="https://cafe.test/about">About</a>';

        $this->assertSame($html, LocalizedLinks::rewrite($html, 'en', 'about'));
    }

    public function test_a_menu_item_shows_its_translated_label(): void
    {
        $menu = Menu::create(['slot' => 'footer', 'label' => 'Footer']);
        $item = MenuItem::create(['menu_id' => $menu->id, 'type' => 'url', 'label' => 'Opening hours', 'url' => '/contact', 'order_column' => 1]);
        Translation::create(['model_type' => 'MenuItem', 'model_key' => $item->id . '_label', 'lang_code' => 'fr', 'translation' => "Horaires d'ouverture"]);

        $this->assertSame('Opening hours', $item->resolveLabel());
        app()->setLocale('fr');
        $this->assertSame("Horaires d'ouverture", $item->resolveLabel());
        app()->setLocale('th');
        $this->assertSame('Opening hours', $item->resolveLabel(), 'untranslated falls back to the source');
        app()->setLocale('en');

        $missing = app(TranslationStatusService::class)->missing('menus', 'th');
        $this->assertContains($item->id, array_column($missing, 'id'));
        $this->assertNotContains($item->id, array_column(app(TranslationStatusService::class)->missing('menus', 'fr'), 'id'));
    }
}
