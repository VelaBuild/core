<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AppearanceThemePickerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_appearance_page_shows_theme_cards(): void
    {
        $this->loginAsAdmin();
        $response = $this->get(route('vela.admin.settings.group', 'appearance'));
        $response->assertStatus(200);
        $response->assertSee('Corporate');
        $response->assertSee('Editorial');
        $response->assertSee('Modern');
        $response->assertSee('Dark');
    }

    public function test_can_switch_active_template(): void
    {
        $this->loginAsAdmin();
        $this->post(route('vela.admin.settings.updateGroup', 'appearance'), [
            'active_template' => 'corporate',
        ]);
        $this->assertEquals('corporate', VelaConfig::where('key', 'active_template')->value('value'));
    }

    public function test_appearance_page_requires_config_access_permission(): void
    {
        $this->loginAsUser();
        $response = $this->get(route('vela.admin.settings.group', 'appearance'));
        $response->assertStatus(403);
    }

    public function test_appearance_shows_active_template_badge(): void
    {
        $this->loginAsAdmin();
        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => 'modern']);
        $response = $this->get(route('vela.admin.settings.group', 'appearance'));
        $response->assertStatus(200);
        $response->assertSee('Active');
    }
}
