<?php

namespace VelaBuild\Core\Tests\Unit\Registries;

use VelaBuild\Core\Registries\TemplateRegistry;
use VelaBuild\Core\Tests\TestCase;

class TemplateRegistryTest extends TestCase
{
    private TemplateRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new TemplateRegistry();
    }

    public function test_can_register_template(): void
    {
        $this->registry->register('default', ['label' => 'Default Template', 'namespace' => 'default']);

        $this->assertTrue($this->registry->has('default'));
    }

    public function test_all_returns_all_templates(): void
    {
        $this->registry->register('default', ['label' => 'Default Template', 'namespace' => 'default']);
        $this->registry->register('minimal', ['label' => 'Minimal Template', 'namespace' => 'minimal']);

        $all = $this->registry->all();

        $this->assertCount(2, $all);
    }

    public function test_register_stores_metadata_fields(): void
    {
        $this->registry->register('corporate', [
            'label' => 'Corporate',
            'namespace' => 'vela-corporate',
            'description' => 'Bold professional design',
            'screenshot' => 'vendor/vela/screenshots/corporate.png',
            'category' => 'professional',
        ]);

        $template = $this->registry->get('corporate');

        $this->assertEquals('Bold professional design', $template['description']);
        $this->assertEquals('vendor/vela/screenshots/corporate.png', $template['screenshot']);
        $this->assertEquals('professional', $template['category']);
    }

    public function test_metadata_defaults_to_empty_strings(): void
    {
        $this->registry->register('basic', ['label' => 'Basic', 'namespace' => 'basic']);

        $template = $this->registry->get('basic');

        $this->assertEquals('', $template['description']);
        $this->assertEquals('', $template['screenshot']);
        $this->assertEquals('', $template['category']);
    }
}
