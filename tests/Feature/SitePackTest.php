<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use VelaBuild\Core\Services\SitePack\DataImporter;
use VelaBuild\Core\Services\SitePack\SitePacker;
use VelaBuild\Core\Tests\PackageTestCase;

class SitePackTest extends PackageTestCase
{
    private string $site;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = sys_get_temp_dir() . '/vela-pack-site-' . uniqid();
        $this->out = sys_get_temp_dir() . '/vela-pack-out-' . uniqid();
        File::makeDirectory($this->out, 0755, true);
        $long = 'resources/views/templates/' . str_repeat('very-long-directory-name/', 5) . 'page.blade.php';
        foreach ([
            'composer.json' => json_encode(['require' => ['php' => '^8.2', 'laravel/framework' => '^13.0', 'velabuild/core' => 'dev-master']]),
            'resources/views/templates/mine/layout.blade.php' => '<html>mine</html>',
            $long => 'deep',
            'storage/app/public/1/photo.jpg' => "\xFF\xD8\xFF\x00binary",
            'vendor/autoload.php' => '<?php // platform',
            'public/vendor/vela/app.css' => 'platform',
            '.env' => 'APP_KEY=secret',
            'storage/logs/laravel.log' => 'noise',
        ] as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$this->site}/{$path}"));
            File::put("{$this->site}/{$path}", $contents);
        }

        config(['app.url' => 'http://localhost:8000', 'app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        Schema::create('widgets', function ($t) {
            $t->id();
            $t->text('body')->nullable();
            $t->binary('blob')->nullable();
        });
        DB::table('widgets')->insert([
            ['body' => '<img src="http://localhost:8000/storage/1/photo.jpg">', 'blob' => "\xFF\x00\xFE"],
            ['body' => serialize(['url' => 'http://localhost:8000/x']), 'blob' => null],
            ['body' => '{"src":"http:\/\/localhost:8000\/storage\/a.png"}', 'blob' => null],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->site);
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    public function test_a_packed_site_unpacks_and_its_rows_load_back_with_local_links_made_relative(): void
    {
        $result = (new SitePacker($this->site))->pack($this->out . '/site.tar.gz');

        $this->assertSame(3, $result['manifest']['tables']['widgets']);
        $this->assertArrayNotHasKey('app_key', $result['manifest']);

        (new \PharData($this->out . '/site.tar.gz'))->extractTo($this->out . '/x');
        $x = $this->out . '/x';
        $manifest = json_decode(File::get("{$x}/vela-manifest.json"), true);
        $this->assertSame(SitePacker::FORMAT, $manifest['format']);
        $this->assertSame(config('app.key'), $manifest['app_key']);
        $this->assertFileExists("{$x}/app/resources/views/templates/mine/layout.blade.php");
        $this->assertFileExists("{$x}/app/resources/views/templates/" . str_repeat('very-long-directory-name/', 5) . 'page.blade.php');
        $this->assertSame("\xFF\xD8\xFF\x00binary", File::get("{$x}/app/storage/app/public/1/photo.jpg"));
        foreach (['vendor', 'public/vendor/vela', '.env', 'storage/logs'] as $skipped) {
            $this->assertFileDoesNotExist("{$x}/app/{$skipped}");
        }

        DB::table('widgets')->delete();
        $report = $this->app->make(DataImporter::class)->import($x);

        $this->assertSame(3, $report['tables']['widgets']);
        $rows = DB::table('widgets')->orderBy('id')->get();
        $this->assertSame('<img src="/storage/1/photo.jpg">', $rows[0]->body);
        $this->assertSame("\xFF\x00\xFE", $rows[0]->blob);
        $this->assertSame(['url' => 'http://localhost:8000/x'], unserialize($rows[1]->body));
        $this->assertSame('{"src":"\/storage\/a.png"}', $rows[2]->body);
    }

    public function test_composer_packages_of_the_sites_own_block_the_move(): void
    {
        File::put("{$this->site}/composer.json", json_encode(['require' => ['php' => '^8.2', 'velabuild/core' => '*', 'acme/shop' => '^1.0']]));

        $packer = new SitePacker($this->site);

        $this->assertSame(['acme/shop'], $packer->ownPackages());
        $this->assertStringContainsString('acme/shop', $packer->blockers()[0]);
    }

    public function test_admins_download_the_archive_and_hosted_sites_have_no_such_page(): void
    {
        $user = $this->signIn();
        \Illuminate\Support\Facades\Gate::define('config_access', fn () => true);
        \Illuminate\Support\Facades\Gate::define('config_edit', fn () => true);
        $this->get(route('vela.admin.move-to-hosted.index'))->assertForbidden();

        $user->roles()->attach(\VelaBuild\Core\Models\Role::firstOrCreate(['id' => 1], ['title' => 'Admin'])->id);
        $this->get(route('vela.admin.move-to-hosted.index'))->assertOk()->assertSee('php artisan vela:push');
        $download = $this->post(route('vela.admin.move-to-hosted.download'))->assertOk();
        $this->assertStringContainsString('vela-site-', (string) $download->headers->get('content-disposition'));
        $this->assertSame(SitePacker::FORMAT, json_decode(file_get_contents(
            'phar://' . $download->getFile()->getPathname() . '/vela-manifest.json'), true)['format'] ?? null);

        config(['vela.hosting.site_id' => 'acme']);
        $this->get(route('vela.admin.move-to-hosted.index'))->assertNotFound();
    }

    public function test_an_archive_without_a_manifest_is_refused(): void
    {
        $this->expectExceptionMessage('not a Vela site archive');
        $this->app->make(DataImporter::class)->import($this->out);
    }
}
