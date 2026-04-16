<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\ToolSettingsService;
use VelaBuild\Core\Tests\TestCase;

class ToolSettingsServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_set_and_get_plain_value(): void
    {
        $service = new ToolSettingsService();
        $service->set('cf_zone_id', 'zone123');

        $this->assertEquals('zone123', $service->get('cf_zone_id'));
    }

    public function test_set_and_get_encrypted_value(): void
    {
        $service = new ToolSettingsService();
        $service->set('cf_api_token', 'secret-token');

        // Raw DB value should be encrypted (not plaintext)
        $raw = VelaConfig::where('key', 'tool_cf_api_token')->value('value');
        $this->assertNotEquals('secret-token', $raw);

        // Service should decrypt transparently
        $this->assertEquals('secret-token', $service->get('cf_api_token'));
    }

    public function test_has_key_returns_true_when_set(): void
    {
        $service = new ToolSettingsService();
        $this->assertFalse($service->hasKey('cf_zone_id'));

        $service->set('cf_zone_id', 'zone123');
        $this->assertTrue($service->hasKey('cf_zone_id'));
    }

    public function test_get_masked_value(): void
    {
        $service = new ToolSettingsService();
        $service->set('cf_api_token', 'abcdefghijklmnop');

        $masked = $service->getMaskedValue('cf_api_token');
        $this->assertStringEndsWith('mnop', $masked);
        $this->assertStringStartsWith('*', $masked);
    }

    public function test_null_value_clears_setting(): void
    {
        $service = new ToolSettingsService();
        $service->set('cf_zone_id', 'zone123');
        $service->set('cf_zone_id', null);

        $this->assertNull($service->get('cf_zone_id'));
    }
}
