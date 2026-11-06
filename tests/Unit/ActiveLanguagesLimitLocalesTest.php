<?php

namespace VelaBuild\Core\Tests\Unit;

use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use VelaBuild\Core\Services\SiteConfigWriter;
use VelaBuild\Core\Tests\TestCase;

class ActiveLanguagesLimitLocalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $all = [];
        foreach (['en' => 'English', 'fr' => 'French', 'de' => 'German', 'th' => 'Thai'] as $code => $name) {
            $all[$code] = ['name' => $name, 'script' => 'Latn', 'native' => $name, 'regional' => $code];
        }
        config([
            'app.locale' => 'en',
            'laravellocalization.supportedLocales' => $all,
            'vela.available_languages' => array_map(fn ($l) => $l['name'], $all),
        ]);
        app('laravellocalization')->setSupportedLocales($all);
    }

    public function test_only_the_sites_active_languages_are_offered(): void
    {
        SiteConfigWriter::apply(['active_languages' => json_encode(['en', 'fr'])]);

        $this->assertSame(['en', 'fr'], array_keys(config('laravellocalization.supportedLocales')));
        $this->assertSame(['en', 'fr'], array_keys(LaravelLocalization::getSupportedLocales()));
    }

    public function test_a_one_language_site_keeps_just_that_language(): void
    {
        SiteConfigWriter::apply(['active_languages' => json_encode(['en'])]);

        $this->assertSame(['en'], array_keys(LaravelLocalization::getSupportedLocales()));
    }

    public function test_the_default_locale_is_always_kept(): void
    {
        SiteConfigWriter::apply(['active_languages' => json_encode(['de'])]);

        $this->assertEqualsCanonicalizing(['en', 'de'], array_keys(LaravelLocalization::getSupportedLocales()));
    }

    public function test_a_one_language_site_can_still_switch_more_on(): void
    {
        SiteConfigWriter::apply(['active_languages' => json_encode(['en'])]);
        $this->assertSame(['en'], array_keys(config('vela.available_languages')));
        $this->assertSame(['en', 'fr', 'de', 'th'], array_keys(SiteConfigWriter::allLanguages()));

        SiteConfigWriter::apply(['active_languages' => json_encode(['en', 'th'])]);
        $this->assertSame(['en', 'th'], array_keys(config('vela.available_languages')));
        $this->assertSame(['en', 'th'], array_keys(LaravelLocalization::getSupportedLocales()));
    }
}
