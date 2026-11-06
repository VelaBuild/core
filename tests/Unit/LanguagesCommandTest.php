<?php

namespace VelaBuild\Core\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\TestCase;

class LanguagesCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vela.available_languages' => ['en' => 'English', 'fr' => 'French', 'de' => 'German'], 'vela.all_languages' => null]);
    }

    public function test_it_sets_the_sites_languages(): void
    {
        $this->artisan('vela:languages', ['languages' => 'fr,de', '--primary' => 'en'])->assertSuccessful();

        $this->assertSame(['en', 'fr', 'de'], json_decode(VelaConfig::where('key', 'active_languages')->value('value'), true));
        $this->assertSame('en', VelaConfig::where('key', 'primary_language')->value('value'));
    }

    public function test_a_one_language_site_can_switch_on_more(): void
    {
        \VelaBuild\Core\Services\SiteConfigWriter::apply(['active_languages' => json_encode(['en'])]);

        $this->artisan('vela:languages', ['languages' => 'en,fr'])->assertSuccessful();
        $this->assertSame(['en', 'fr'], json_decode(VelaConfig::where('key', 'active_languages')->value('value'), true));
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->artisan('vela:languages', ['languages' => 'en,xx'])->assertFailed();
        $this->assertNull(VelaConfig::where('key', 'active_languages')->value('value'));
    }
}
