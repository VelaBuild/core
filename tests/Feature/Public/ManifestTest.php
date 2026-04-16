<?php

namespace VelaBuild\Core\Tests\Feature\Public;

use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ManifestTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear manifest file cache so each test generates a fresh manifest
        $cacheDir = storage_path('app/pwa');
        if (is_dir($cacheDir)) {
            foreach (glob("{$cacheDir}/manifest-*.json") as $file) {
                unlink($file);
            }
        }
    }

    public function test_manifest_returns_json(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '1']);

        $response = $this->get('/manifest.webmanifest');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/manifest+json');
    }

    public function test_manifest_contains_required_fields(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '1']);
        VelaConfig::updateOrCreate(['key' => 'pwa_name'], ['value' => 'Test PWA']);

        $response = $this->get('/manifest.webmanifest');
        $data = $response->json();

        $this->assertEquals('Test PWA', $data['name']);
        $this->assertArrayHasKey('short_name', $data);
        $this->assertArrayHasKey('start_url', $data);
        $this->assertArrayHasKey('display', $data);
        $this->assertArrayHasKey('theme_color', $data);
        $this->assertArrayHasKey('background_color', $data);
    }

    public function test_manifest_uses_defaults_when_no_config(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '1']);

        $response = $this->get('/manifest.webmanifest');
        $data = $response->json();

        $this->assertEquals(config('app.name'), $data['name']);
        $this->assertEquals('standalone', $data['display']);
    }

    public function test_manifest_returns_404_when_pwa_disabled(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '0']);

        $response = $this->get('/manifest.webmanifest');
        $response->assertStatus(404);
    }

    public function test_manifest_accepts_locale_parameter(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '1']);

        $response = $this->get('/manifest.webmanifest?lang=de');
        $data = $response->json();

        $this->assertEquals('de', $data['lang']);
    }
}
