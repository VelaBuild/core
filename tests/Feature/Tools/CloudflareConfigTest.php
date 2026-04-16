<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use VelaBuild\Core\Jobs\PurgeCloudflareCacheJob;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\ToolSettingsService;
use VelaBuild\Core\Tests\TestCase;

class CloudflareConfigTest extends TestCase
{
    use DatabaseTransactions;

    public function test_save_cloudflare_config(): void
    {
        $this->loginAsAdmin();

        $response = $this->post(route('vela.admin.tools.cloudflare.config'), [
            'cf_api_token' => 'test-token-12345678',
            'cf_zone_id' => 'zone123',
            'cf_purge_mode' => 'smart',
        ]);

        $response->assertRedirect();

        $settings = app(ToolSettingsService::class);
        $this->assertEquals('zone123', $settings->get('cf_zone_id'));
        $this->assertEquals('test-token-12345678', $settings->get('cf_api_token'));
    }

    public function test_auto_purge_on_content_save(): void
    {
        Bus::fake();

        $settings = app(ToolSettingsService::class);
        $settings->set('cf_api_token', 'test-token-12345678');
        $settings->set('cf_zone_id', 'zone123');

        Content::create([
            'title' => 'CF Purge Test',
            'type' => 'post',
            'status' => 'published',
            'content' => 'Test content',
        ]);

        Bus::assertDispatched(PurgeCloudflareCacheJob::class);
    }
}
