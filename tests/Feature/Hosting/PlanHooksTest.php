<?php

namespace VelaBuild\Core\Tests\Feature\Hosting;

use VelaBuild\Core\Commands\ExportStatic;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Support\Seats;
use VelaBuild\Core\Tests\TestCase;
use ZipArchive;

/** Hosted plan hooks: VELA_FEATURES, VELA_SEATS (set by the control plane). */
class PlanHooksTest extends TestCase
{
    public function test_features_are_all_on_when_self_hosted_and_listed_only_when_hosted(): void
    {
        config(['vela.hosting.features' => null]);
        $this->assertTrue(vela_feature('export'));
        $this->assertTrue(vela_feature('anything'));

        config(['vela.hosting.features' => 'marketplace, previews']);
        $this->assertTrue(vela_feature('marketplace'));
        $this->assertTrue(vela_feature('previews'));
        $this->assertFalse(vela_feature('export'));

        config(['vela.hosting.features' => '']);
        $this->assertFalse(vela_feature('marketplace'), 'hosted with no features means none');
    }

    public function test_seat_limit_is_null_when_unset(): void
    {
        config(['vela.hosting.seats' => null]);
        $this->assertNull(vela_seat_limit());
        config(['vela.hosting.seats' => '5']);
        $this->assertSame(5, vela_seat_limit());
    }

    public function test_creating_a_cms_user_beyond_the_plans_seats_is_refused(): void
    {
        Permission::firstOrCreate(['title' => 'user_create']);
        $admin = $this->loginAsAdmin();
        $adminRole = Role::firstOrCreate(['id' => 1], ['title' => 'Admin']);
        config(['vela.hosting.seats' => (string) Seats::used()]);  // already full

        $email = 'seat_' . uniqid() . '@example.com';
        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'One Too Many', 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
            'roles' => [$adminRole->id],
        ])->assertRedirect('/admin/users/create')->assertSessionHasErrors('roles');
        $this->assertDatabaseMissing('vela_users', ['email' => $email]);

        // A Guest-only account doesn't take a seat.
        $this->post('/admin/users', [
            'name' => 'Visitor', 'email' => 'guest_' . $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
            'roles' => [Role::guest()->id],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vela_users', ['email' => 'guest_' . $email]);

        // One more seat on the plan: allowed.
        config(['vela.hosting.seats' => (string) (Seats::used() + 1)]);
        $this->post('/admin/users', [
            'name' => 'Fits', 'email' => 'fits_' . $email,
            'password' => 'password123', 'password_confirmation' => 'password123',
            'roles' => [$adminRole->id],
        ])->assertSessionHasNoErrors();
    }

    public function test_promoting_a_guest_beyond_the_seats_is_refused_but_editing_a_seat_holder_is_not(): void
    {
        Permission::firstOrCreate(['title' => 'user_edit']);
        $this->loginAsAdmin();
        $adminRole = Role::firstOrCreate(['id' => 1], ['title' => 'Admin']);
        $guest = VelaUser::factory()->create();
        $guest->roles()->sync([Role::guest()->id]);
        config(['vela.hosting.seats' => (string) Seats::used()]);

        $this->from('/admin/users/' . $guest->id . '/edit')->put('/admin/users/' . $guest->id, [
            'name' => $guest->name, 'email' => $guest->email, 'roles' => [$adminRole->id],
        ])->assertSessionHasErrors('roles');
        $this->assertFalse($guest->roles()->where('vela_roles.id', $adminRole->id)->exists());

        $holder = VelaUser::whereHas('roles', fn ($q) => $q->where('vela_roles.id', '!=', Role::guest()->id))->first();
        config(['vela.hosting.seats' => '1']);  // plan shrank below current use
        $this->put('/admin/users/' . $holder->id, [
            'name' => 'Renamed', 'email' => $holder->email, 'roles' => [$adminRole->id],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vela_users', ['id' => $holder->id, 'name' => 'Renamed']);
    }

    public function test_static_export_maps_pages_to_their_urls_and_respects_the_plan(): void
    {
        $this->assertSame('index.html', ExportStatic::urlPathFor('home/index.html'));
        $this->assertSame('th/index.html', ExportStatic::urlPathFor('home/translations/th.html'));
        $this->assertSame('about/index.html', ExportStatic::urlPathFor('pages/about/index.html'));
        $this->assertSame('docs/install/index.html', ExportStatic::urlPathFor('pages/docs/install/index.html'));
        $this->assertSame('zh-Hans/about/index.html', ExportStatic::urlPathFor('pages/about/translations/zh-Hans.html'));
        $this->assertSame('posts/index.html', ExportStatic::urlPathFor('posts/index.html'));
        $this->assertSame('posts/hello/index.html', ExportStatic::urlPathFor('posts/hello/index.html'));
        $this->assertSame('th/posts/hello/index.html', ExportStatic::urlPathFor('posts/hello/translations/th.html'));
        $this->assertSame('categories/news/index.html', ExportStatic::urlPathFor('categories/news/index.html'));
        $this->assertNull(ExportStatic::urlPathFor('pages/index.html'));
        $this->assertNull(ExportStatic::urlPathFor('pages/about/config.json'));

        config(['vela.hosting.features' => 'marketplace']);
        $this->artisan('vela:export-static')->assertFailed();

        config(['vela.hosting.features' => 'marketplace,export', 'app.url' => 'https://acme.test']);
        $static = sys_get_temp_dir() . '/vela-static-' . uniqid();
        @mkdir($static . '/pages/about', 0777, true);
        @mkdir($static . '/home', 0777, true);
        file_put_contents($static . '/home/index.html', '<a href="https://acme.test/about">About</a>');
        file_put_contents($static . '/pages/about/index.html', '<h1>About</h1>');
        config(['vela.static.path' => $static]);
        $out = sys_get_temp_dir() . '/vela-export-' . uniqid() . '.zip';

        $this->artisan('vela:export-static', ['--output' => $out])->assertSuccessful();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($out));
        $this->assertSame('<a href="/about">About</a>', $zip->getFromName('index.html'));
        $this->assertSame('<h1>About</h1>', $zip->getFromName('about/index.html'));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringEndsNotWith('.php', $zip->getNameIndex($i), 'no PHP in a static export');
        }
        $zip->close();
    }
}
