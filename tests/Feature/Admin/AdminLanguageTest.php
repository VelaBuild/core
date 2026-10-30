<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use Illuminate\Support\Facades\Schema;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Services\AdminLanguage;
use VelaBuild\Core\Tests\TestCase;

/**
 * The admin panel's language, chosen apart from the site's content languages:
 * per account, then per session, then vela.admin_locale, then primary_language.
 */
class AdminLanguageTest extends TestCase
{
    private const PROFILE = '/vela/profile/password';

    public function test_the_users_table_has_the_column(): void
    {
        $this->assertTrue(Schema::hasColumn('vela_users', 'admin_locale'));
    }

    public function test_without_any_choice_the_admin_uses_the_primary_language(): void
    {
        config(['vela.admin_locale' => null, 'vela.primary_language' => 'en']);
        $this->loginAsAdmin();

        $this->get(self::PROFILE)->assertOk()->assertSee('<html lang="en">', false);
    }

    public function test_the_site_default_admin_language_applies_to_accounts_without_a_choice(): void
    {
        config(['vela.admin_locale' => 'de']);
        $this->loginAsAdmin();

        $this->get(self::PROFILE)
            ->assertOk()
            ->assertSee('<html lang="de">', false)
            ->assertSee('Sprache der Verwaltung');
    }

    public function test_an_accounts_own_choice_beats_the_site_default(): void
    {
        config(['vela.admin_locale' => 'de']);
        $user = $this->loginAsAdmin();
        $user->forceFill(['admin_locale' => 'fr'])->save();

        $this->get(self::PROFILE)->assertOk()->assertSee('<html lang="fr">', false);
    }

    public function test_a_choice_that_is_no_longer_available_falls_back_to_the_default(): void
    {
        config(['vela.admin_locale' => 'de']);
        $user = $this->loginAsAdmin();
        $user->forceFill(['admin_locale' => 'xx'])->save();

        $this->get(self::PROFILE)->assertOk()->assertSee('<html lang="de">', false);
    }

    public function test_the_profile_page_saves_the_accounts_language(): void
    {
        config(['vela.admin_locale' => 'de']);
        $user = $this->loginAsAdmin();

        $this->post('/vela/profile/language', ['admin_locale' => 'en'])
            ->assertRedirect(self::PROFILE)
            ->assertSessionHas('message', 'Admin language saved.')
            ->assertSessionHas(AdminLanguage::SESSION_KEY, 'en');

        $this->assertSame('en', $user->fresh()->admin_locale);
        $this->get(self::PROFILE)->assertSee('<html lang="en">', false);
    }

    public function test_the_confirmation_is_in_the_language_just_chosen(): void
    {
        config(['vela.admin_locale' => 'en']);
        $this->loginAsAdmin();

        $this->post('/vela/profile/language', ['admin_locale' => 'de'])
            ->assertSessionHas('message', 'Sprache der Verwaltung gespeichert.');
    }

    public function test_choosing_the_site_default_clears_the_accounts_choice(): void
    {
        config(['vela.admin_locale' => 'de']);
        $user = $this->loginAsAdmin();
        $user->forceFill(['admin_locale' => 'en'])->save();

        $this->withSession([AdminLanguage::SESSION_KEY => 'en'])
            ->post('/vela/profile/language', ['admin_locale' => ''])
            ->assertRedirect(self::PROFILE)
            ->assertSessionMissing(AdminLanguage::SESSION_KEY);

        $this->assertNull($user->fresh()->admin_locale);
        $this->get(self::PROFILE)->assertSee('<html lang="de">', false);
    }

    public function test_the_profile_page_refuses_a_language_the_admin_has_no_translations_for(): void
    {
        $user = $this->loginAsAdmin();
        $user->forceFill(['admin_locale' => 'de'])->save();

        $this->from(self::PROFILE)
            ->post('/vela/profile/language', ['admin_locale' => 'xx'])
            ->assertRedirect(self::PROFILE)
            ->assertSessionHasErrors('admin_locale');

        $this->assertSame('de', $user->fresh()->admin_locale);
    }

    public function test_the_profile_page_needs_the_profile_permission(): void
    {
        $user = $this->loginAsUser();

        $this->post('/vela/profile/language', ['admin_locale' => 'de'])->assertForbidden();
        $this->assertNull($user->fresh()->admin_locale);
    }

    public function test_the_profile_page_shows_the_choice(): void
    {
        config(['vela.admin_locale' => 'en']);
        $user = $this->loginAsAdmin();
        $user->forceFill(['admin_locale' => 'en'])->save();

        $this->get(self::PROFILE)
            ->assertOk()
            ->assertSee('name="admin_locale"', false)
            ->assertSee('Site default (English)')
            ->assertSee('<option value="en" selected>English</option>', false)
            ->assertSee('<option value="de" >Deutsch</option>', false);
    }

    public function test_the_top_bar_switch_is_kept_for_the_account(): void
    {
        config(['vela.admin_locale' => 'en']);
        $user = $this->loginAsAdmin();

        $this->get(self::PROFILE . '?change_language=de')->assertRedirect(url(self::PROFILE));

        $this->assertSame('de', $user->fresh()->admin_locale);
        $this->get(self::PROFILE)->assertSee('<html lang="de">', false);
    }

    public function test_the_top_bar_switch_ignores_an_unknown_language(): void
    {
        $user = $this->loginAsAdmin();
        $user->forceFill(['admin_locale' => 'de'])->save();

        $this->get(self::PROFILE . '?change_language=xx')->assertRedirect(url(self::PROFILE));

        $this->assertSame('de', $user->fresh()->admin_locale);
    }

    public function test_the_top_bar_lists_the_admin_languages_even_when_the_site_has_one_content_language(): void
    {
        VelaConfig::updateOrCreate(['key' => 'active_languages'], ['value' => json_encode(['en'])]);
        $this->loginAsAdmin();

        $this->get(self::PROFILE)
            ->assertOk()
            ->assertSee('?change_language=de', false)
            ->assertSee('Deutsch');
    }

    public function test_the_login_page_uses_the_site_default_admin_language(): void
    {
        config(['vela.admin_locale' => 'de']);

        $this->get('/vela/login')->assertOk()->assertSee('<html lang="de">', false);
    }

    public function test_the_login_page_can_be_switched_for_the_session(): void
    {
        config(['vela.admin_locale' => 'de']);

        $this->get('/vela/login?change_language=en')
            ->assertRedirect(url('/vela/login'))
            ->assertSessionHas(AdminLanguage::SESSION_KEY, 'en');

        $this->get('/vela/login')->assertSee('<html lang="en">', false);
    }

    public function test_an_administrator_sets_the_language_of_a_new_account(): void
    {
        Permission::firstOrCreate(['title' => 'user_create']);
        $this->loginAsAdmin();
        $email = 'language_' . uniqid() . '@example.com';

        $this->post('/admin/users', [
            'name' => 'Language User',
            'email' => $email,
            'password' => 'password123',
            'roles' => [Role::firstOrCreate(['id' => 2], ['title' => 'User'])->id],
            'admin_locale' => 'de',
        ])->assertRedirect();

        $this->assertDatabaseHas('vela_users', ['email' => $email, 'admin_locale' => 'de']);
    }

    public function test_an_administrator_changes_and_clears_an_accounts_language(): void
    {
        Permission::firstOrCreate(['title' => 'user_edit']);
        $this->loginAsAdmin();
        $user = VelaUser::factory()->create();
        $role = Role::firstOrCreate(['id' => 2], ['title' => 'User'])->id;

        $this->put('/admin/users/' . $user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [$role],
            'admin_locale' => 'th',
        ])->assertRedirect();
        $this->assertSame('th', $user->fresh()->admin_locale);

        $this->put('/admin/users/' . $user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [$role],
            'admin_locale' => null,
        ])->assertRedirect();
        $this->assertNull($user->fresh()->admin_locale);
    }

    public function test_an_administrator_cannot_set_an_unknown_language(): void
    {
        Permission::firstOrCreate(['title' => 'user_edit']);
        $this->loginAsAdmin();
        $user = VelaUser::factory()->create();

        $this->put('/admin/users/' . $user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [Role::firstOrCreate(['id' => 2], ['title' => 'User'])->id],
            'admin_locale' => 'xx',
        ])->assertSessionHasErrors('admin_locale');

        $this->assertNull($user->fresh()->admin_locale);
    }

    public function test_the_user_form_offers_the_languages(): void
    {
        Permission::firstOrCreate(['title' => 'user_edit']);
        $this->loginAsAdmin();
        $user = VelaUser::factory()->create();
        $user->forceFill(['admin_locale' => 'nl'])->save();

        $this->get('/admin/users/' . $user->id . '/edit')
            ->assertOk()
            ->assertSee('<option value="nl" selected>Nederlands</option>', false);
    }

    public function test_only_languages_with_admin_translations_are_offered(): void
    {
        config(['vela.available_languages' => ['en' => 'English', 'de' => 'German', 'es' => 'Spanish']]);

        $languages = app(AdminLanguage::class);

        $this->assertSame(['en' => 'English', 'de' => 'Deutsch'], $languages->available());
        $this->assertFalse($languages->isAvailable('es'));
    }

    public function test_an_app_level_translation_makes_a_language_available(): void
    {
        config(['vela.available_languages' => ['en' => 'English', 'es' => 'Spanish']]);
        $dir = lang_path('vendor/vela/es');
        $created = !is_dir($dir) && mkdir($dir, 0755, true);

        try {
            $this->assertSame(['en' => 'English', 'es' => 'Spanish'], app(AdminLanguage::class)->available());
        } finally {
            if ($created) {
                rmdir($dir);
            }
        }
    }

    public function test_the_default_falls_back_when_the_configured_language_is_unavailable(): void
    {
        config(['vela.admin_locale' => 'xx', 'vela.primary_language' => 'de']);

        $this->assertSame('de', app(AdminLanguage::class)->default());
    }
}
