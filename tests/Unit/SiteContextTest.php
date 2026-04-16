<?php

namespace VelaBuild\Core\Tests\Unit;

use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\SiteContext;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class SiteContextTest extends TestCase
{
    use DatabaseTransactions;

    public function test_returns_config_defaults_when_no_db_overrides(): void
    {
        config([
            'vela.ai.site_context.name' => 'Config Site Name',
            'vela.ai.site_context.niche' => 'cooking',
            'vela.ai.site_context.description' => '',
        ]);

        $context = new SiteContext();

        $this->assertEquals('Config Site Name', $context->getName());
        $this->assertEquals('cooking', $context->getNiche());
    }

    public function test_db_overrides_config_values(): void
    {
        config([
            'vela.ai.site_context.name' => 'Config Name',
            'vela.ai.site_context.niche' => 'general',
        ]);

        VelaConfig::create(['key' => 'site_name', 'value' => 'Override Name']);

        $context = new SiteContext();

        $this->assertEquals('Override Name', $context->getName());
    }

    public function test_get_description_formats_correctly(): void
    {
        config([
            'vela.ai.site_context.name' => 'My Site',
            'vela.ai.site_context.niche' => 'cooking',
            'vela.ai.site_context.description' => '',
        ]);

        $context = new SiteContext();
        $description = $context->getDescription();

        $this->assertStringContainsString('cooking website', $description);
        $this->assertStringContainsString("'My Site'", $description);
    }

    public function test_handles_empty_values_gracefully(): void
    {
        config([
            'vela.ai.site_context.name' => '',
            'vela.ai.site_context.niche' => '',
            'vela.ai.site_context.description' => '',
        ]);

        $context = new SiteContext();
        $description = $context->getDescription();

        $this->assertNotEmpty($description);
    }
}
