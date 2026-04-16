<?php

namespace VelaBuild\Core\Tests\Feature\Public;

use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ServiceWorkerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sw_returns_javascript(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '1']);
        VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => '1']);

        $response = $this->get('/sw.js');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/javascript');
        $response->assertHeader('Service-Worker-Allowed', '/');
    }

    public function test_sw_contains_version(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '1']);
        VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => '42']);

        $response = $this->get('/sw.js');
        $this->assertStringContainsString('vela-cache-v42', $response->getContent());
    }

    public function test_sw_unregisters_when_pwa_disabled(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '0']);

        $response = $this->get('/sw.js');
        $response->assertStatus(200);
        $this->assertStringContainsString('unregister', $response->getContent());
    }

    public function test_sw_includes_precache_urls(): void
    {
        VelaConfig::updateOrCreate(['key' => 'pwa_enabled'], ['value' => '1']);
        VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => '1']);
        VelaConfig::updateOrCreate(['key' => 'pwa_precache_urls'], ['value' => '/posts,/about']);

        $response = $this->get('/sw.js');
        $this->assertStringContainsString('/posts', $response->getContent());
        $this->assertStringContainsString('/about', $response->getContent());
    }
}
