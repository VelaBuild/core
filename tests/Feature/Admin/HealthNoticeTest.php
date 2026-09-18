<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use VelaBuild\Core\Services\SiteHealth;
use VelaBuild\Core\Tests\TestCase;

/**
 * The admin says what the site still needs after an update. Nothing said it
 * before, so a site running new code against an old database only found out
 * when something broke in front of a visitor.
 */
class HealthNoticeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        SiteHealth::forget();
    }

    protected function tearDown(): void
    {
        SiteHealth::forget();
        parent::tearDown();
    }

    public function test_a_pending_migration_is_named_on_every_admin_page(): void
    {
        $this->loginAsAdmin();
        $this->get(route('vela.admin.pages.index'))->assertOk()->assertDontSee('php artisan vela:update');

        DB::table('migrations')->where('migration', 'like', '%create_vela_pages_table')->delete();
        SiteHealth::forget();

        $this->get(route('vela.admin.pages.index'))
            ->assertOk()
            ->assertSee('php artisan vela:update')
            ->assertSee('create_vela_pages_table');
    }

    public function test_someone_who_cannot_change_settings_is_not_shown_a_command_they_cannot_run(): void
    {
        DB::table('migrations')->where('migration', 'like', '%create_vela_pages_table')->delete();
        SiteHealth::forget();

        $this->loginAsUser();

        $response = $this->get(route('vela.admin.pages.index'));
        if ($response->status() === 200) {
            $response->assertDontSee('php artisan vela:update');
        } else {
            $this->assertContains($response->status(), [302, 403]);
        }
    }
}
