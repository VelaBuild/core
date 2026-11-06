<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\TestCase;

class DemoModeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Testbench's HTTP kernel resets the web group the provider pushes
        // onto (a real app's kernel keeps it), so add the guard here.
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
            ->appendMiddlewareToGroup('web', \VelaBuild\Core\Http\Middleware\DemoGuard::class);
    }

    private function demoUser(): VelaUser
    {
        return VelaUser::factory()->create(['email' => 'demo@vela.build', 'password' => Hash::make('velademo')]);
    }

    public function test_off_by_default_even_where_every_feature_is_on(): void
    {
        config(['vela.demo.enabled' => false, 'vela.hosting.features' => null]);
        $this->assertFalse(vela_demo_mode());
        $this->get(route('vela.auth.login'))->assertOk()->assertDontSee('Try the full admin');
    }

    public function test_hosted_sites_turn_it_on_with_the_demo_feature(): void
    {
        config(['vela.demo.enabled' => false, 'vela.hosting.features' => 'publish, demo']);
        $this->assertTrue(vela_demo_mode());
    }

    public function test_the_login_page_gives_and_prefills_the_demo_account(): void
    {
        config(['vela.demo.enabled' => true]);
        $this->get(route('vela.auth.login'))->assertOk()
            ->assertSee('Try the full admin')
            ->assertSee('value="demo@vela.build"', false)
            ->assertSee('value="velademo"', false);
    }

    public function test_the_shared_account_cannot_be_locked_or_removed(): void
    {
        config(['vela.demo.enabled' => true]);
        $demo = $this->loginAsAdmin();   // the demo account has the full admin
        $demo->forceFill(['email' => 'demo@vela.build', 'password' => Hash::make('velademo')])->save();

        $this->from(route('vela.auth.profile.password.edit'))->post(route('vela.auth.profile.password.update'), [
            'password' => 'taken-over-1', 'password_confirmation' => 'taken-over-1',
        ])->assertRedirect(route('vela.auth.profile.password.edit'));
        $this->postJson(route('vela.auth.profile.password.toggleTwoFactor'))->assertForbidden();
        $this->putJson(route('vela.admin.users.update', $demo), ['email' => 'x@example.com'])->assertForbidden();
        $this->deleteJson(route('vela.admin.users.destroy', $demo))->assertForbidden();
        $this->deleteJson(route('vela.admin.users.massDestroy'), ['ids' => [$demo->id]])->assertForbidden();

        $this->assertTrue(Hash::check('velademo', $demo->fresh()->password));
        $this->assertSame('demo@vela.build', $demo->fresh()->email);
    }

    public function test_the_model_guard_catches_changes_from_anywhere_else(): void
    {
        config(['vela.demo.enabled' => true]);
        // Demo mode is decided at boot; run that step now it's on.
        (new \ReflectionMethod(\VelaBuild\Core\VelaServiceProvider::class, 'bootDemoMode'))
            ->invoke($this->app->getProvider(\VelaBuild\Core\VelaServiceProvider::class));
        $demo = $this->demoUser();

        $demo->update(['password' => Hash::make('other'), 'email' => 'other@example.com', 'name' => 'Renamed']);
        $demo->delete();

        $fresh = VelaUser::find($demo->id);
        $this->assertNotNull($fresh, 'not deleted');
        $this->assertSame(['demo@vela.build', 'Renamed'], [$fresh->email, $fresh->name]);
        $this->assertTrue(Hash::check('velademo', $fresh->password));
        $this->assertSame('log', config('mail.default'));
    }

    public function test_the_next_reset_is_on_the_reset_hour_grid(): void
    {
        config(['vela.demo.reset_hours' => 3]);
        Carbon::setTestNow('2026-11-04 04:20:00 UTC');
        $this->assertSame('2026-11-04 06:00', vela_demo_next_reset()->format('Y-m-d H:i'));
        Carbon::setTestNow('2026-11-04 23:10:00 UTC');
        $this->assertSame('2026-11-05 00:00', vela_demo_next_reset()->format('Y-m-d H:i'));
        Carbon::setTestNow();
    }
}
