<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Services\ToolSettingsService;
use VelaBuild\Core\Tests\TestCase;

class GoogleAnalyticsConfigTest extends TestCase
{
    use DatabaseTransactions;

    public function test_save_measurement_id(): void
    {
        $this->loginAsAdmin();

        $response = $this->post(route('vela.admin.tools.google-analytics.config'), [
            'ga_measurement_id' => 'G-TEST123',
        ]);

        $response->assertRedirect();

        $settings = app(ToolSettingsService::class);
        $this->assertEquals('G-TEST123', $settings->get('ga_measurement_id'));
    }

    public function test_reports_endpoint_returns_json(): void
    {
        $this->loginAsAdmin();

        $response = $this->getJson(route('vela.admin.tools.google-analytics.reports', ['range' => '7daysAgo']));
        $response->assertOk();
        $response->assertJsonStructure(['data', 'cached_at']);
    }

    public function test_reports_rejects_invalid_range(): void
    {
        $this->loginAsAdmin();

        // Invalid range should default to 30daysAgo, not error
        $response = $this->getJson(route('vela.admin.tools.google-analytics.reports', ['range' => 'invalid']));
        $response->assertOk();
    }
}
