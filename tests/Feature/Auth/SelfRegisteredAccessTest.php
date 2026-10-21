<?php

namespace VelaBuild\Core\Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use VelaBuild\Core\Database\Seeders\VelaPermissionsSeeder;
use VelaBuild\Core\Database\Seeders\VelaRolesSeeder;
use VelaBuild\Core\Models\Idea;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What an account that signed itself up can reach.
 *
 * The answer must be the dashboard and its own profile — everything else is
 * for an administrator to grant. These tests come at that from both ends: an
 * actual sign-up through the register form, then every admin route asked as
 * that account; and every admin controller action read for a permission check,
 * so a new route without one fails here rather than in production.
 */
class SelfRegisteredAccessTest extends PackageTestCase
{
    /**
     * Admin actions that are meant to be open to any signed-in account.
     */
    private const OPEN_TO_EVERY_ACCOUNT = [
        'vela.admin.home',                          // the dashboard
        'vela.admin.dashboard.preferences',         // its own widget layout
        'vela.admin.configs.index',                 // redirect to Settings, which is gated
        'vela.admin.settings.appearance.previewExit', // ends the viewer's own theme preview
        'vela.admin.contents.show',                 // redirect to the public post
    ];

    protected function setUp(): void
    {
        parent::setUp();

        (new VelaPermissionsSeeder())->run();
        (new VelaRolesSeeder())->run();
    }

    private function signUp(string $email = 'signup@example.test'): VelaUser
    {
        VelaConfig::updateOrCreate(['key' => 'registration_enabled'], ['value' => '1']);

        $this->post('/vela/register', [
            'name'                  => 'Self Registered',
            'email'                 => $email,
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('vela.admin.home'));

        return VelaUser::where('email', $email)->firstOrFail();
    }

    public function test_a_self_registered_account_gets_only_the_guest_role(): void
    {
        $user = $this->signUp();

        $this->assertSame([Role::GUEST_TITLE], $user->roles->pluck('title')->all());
        $this->assertSame(['profile_password_edit'], $user->roles->flatMap->permissions->pluck('title')->all());
    }

    public function test_a_self_registered_account_reaches_the_dashboard_and_its_profile(): void
    {
        $other = VelaUser::create(['name' => 'Someone Else', 'email' => 'someone-else@example.test', 'password' => 'secret-password']);
        Idea::create(['name' => 'Secret plan for next quarter', 'status' => 'planned']);

        $this->signUp();

        $this->get('/admin')
            ->assertOk()
            ->assertDontSee($other->email)
            ->assertDontSee('Secret plan for next quarter')
            ->assertDontSee(route('vela.admin.users.index'))
            ->assertDontSee(route('vela.admin.pages.index'));

        $this->get('/vela/profile/password')->assertOk();
    }

    public function test_every_other_admin_route_refuses_a_self_registered_account(): void
    {
        $this->signUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $reached = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'vela.admin.') || in_array($name, self::OPEN_TO_EVERY_ACCOUNT, true)) {
                continue;
            }

            $uri = '/' . preg_replace(['/\{[^}]+\?\}/', '/\{[^}]+\}/'], ['', '1'], $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->call($method, $uri);

                // Opening a record redirects to its edit screen
                // (VelaRedirectShowToEdit); what counts is that screen.
                if ($method === 'GET' && $response->isRedirect() && str_ends_with((string) $response->headers->get('Location'), $uri . '/edit')) {
                    $response = $this->get($uri . '/edit');
                }

                $status = $response->getStatusCode();

                // 403 is a refusal; 404 is a route-model binding that found no
                // row before the controller ran. Anything else means the
                // request got into code it should not have — a 500 included,
                // which is what an ungated action does with an empty request.
                if (! in_array($status, [403, 404], true)) {
                    $reached[] = "{$status} {$method} {$uri} ({$name})";
                }
            }
        }

        $this->assertSame([], $reached, "A self-registered account reached:\n" . implode("\n", $reached));
    }

    /**
     * A 404 in the sweep above can hide a missing check behind a missing row,
     * so every action is also read for one.
     */
    public function test_every_admin_action_checks_a_permission(): void
    {
        $unchecked = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'vela.admin.') || in_array($name, self::OPEN_TO_EVERY_ACCOUNT, true)) {
                continue;
            }

            $action = $route->getActionName();
            [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];

            if (! method_exists($class, $method)) {
                $unchecked[] = "{$name} (closure)";
                continue;
            }

            $reflection = new \ReflectionMethod($class, $method);
            $source = implode('', array_slice(
                file($reflection->getFileName()),
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1
            ));

            // A Gate call, a helper that makes one, or a FormRequest whose
            // authorize() does.
            $checked = preg_match('/Gate::|->authori[sz]e|csvImportModelName|mediaUploadGates/', $source) === 1;

            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), \Illuminate\Foundation\Http\FormRequest::class)) {
                    $checked = true;
                }
            }

            if (! $checked) {
                $unchecked[] = "{$name} ({$class}@{$method})";
            }
        }

        $this->assertSame([], $unchecked, "Admin actions with no permission check:\n" . implode("\n", $unchecked));
    }

    public function test_csv_import_writes_only_the_controllers_own_model(): void
    {
        $user = VelaUser::create(['name' => 'Idea Editor', 'email' => 'ideas@example.test', 'password' => 'secret-password']);
        $role = Role::create(['title' => 'Idea importer']);
        $role->permissions()->sync(Permission::where('title', 'idea_create')->pluck('id'));
        $user->roles()->attach($role);
        $this->actingAs($user, 'vela');
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $csv = UploadedFile::fake()->createWithContent('ideas.csv', "name,status,id\nImported idea,planned,999\n");
        $this->post(route('vela.admin.ideas.parseCsvImport'), ['csv_file' => $csv, 'header' => 1, 'model' => 'VelaConfig'])
            ->assertOk()
            ->assertSee(route('vela.admin.ideas.processCsvImport'), false);

        $filename = basename(glob(\Illuminate\Support\Facades\Storage::disk('local')->path('csv_import/*.csv'))[0]);

        $this->post(route('vela.admin.ideas.processCsvImport'), [
            'filename'  => $filename,
            'hasHeader' => 1,
            'modelName' => 'VelaConfig',             // ignored: this controller imports ideas
            'fields'    => [0 => 'name', 1 => 'status', 2 => 'id'], // id is not fillable
            'redirect'  => 'https://elsewhere.example/',
        ])->assertRedirect();

        $idea = Idea::where('name', 'Imported idea')->first();
        $this->assertNotNull($idea);
        $this->assertNotSame(999, (int) $idea->id);
        $this->assertNull(VelaConfig::where('key', 'Imported idea')->first());
    }

    public function test_csv_import_and_uploads_are_refused_without_permission(): void
    {
        $this->signUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $csv = UploadedFile::fake()->createWithContent('users.csv', "name,email\nx,x@example.test\n");

        $this->post(route('vela.admin.users.parseCsvImport'), ['csv_file' => $csv, 'model' => 'VelaUser'])->assertForbidden();
        $this->post(route('vela.admin.users.processCsvImport'), ['filename' => 'x.csv', 'modelName' => 'VelaUser'])->assertForbidden();
        $this->post(route('vela.admin.pages.storeMedia'), ['file' => UploadedFile::fake()->create('a.php', 1)])->assertForbidden();
        $this->post(route('vela.admin.media.storeMedia'), ['file' => UploadedFile::fake()->create('a.php', 1)])->assertForbidden();

        // Their own profile picture is theirs to upload.
        $this->post(route('vela.auth.profile.password.storeMedia'), ['file' => UploadedFile::fake()->image('me.png')])->assertOk();
    }
}
