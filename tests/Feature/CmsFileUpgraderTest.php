<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Services\CmsFileUpgrader;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A site is the starter plus whatever its owner (and their AI) changed in it.
 * An update must bring the starter's fixes in without trampling those changes:
 * files the site never touched update by themselves, files both sides changed
 * wait for a decision, and nothing is ever written outside the site.
 */
class CmsFileUpgraderTest extends PackageTestCase
{
    private string $root;

    private string $site;

    private string $skeleton;

    private string $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/vela-upgrader-' . bin2hex(random_bytes(4));
        $this->site = "{$this->root}/site";
        $this->skeleton = "{$this->root}/skeleton";
        $this->state = "{$this->root}/state";
        mkdir($this->site, 0755, true);
        mkdir("{$this->skeleton}/files", 0755, true);
        CmsFileUpgrader::forget();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function upstream(string $path, string $content): void
    {
        @mkdir(dirname("{$this->skeleton}/files/{$path}"), 0755, true);
        file_put_contents("{$this->skeleton}/files/{$path}", $content);
    }

    private function siteFile(string $path, ?string $content): void
    {
        $file = "{$this->site}/{$path}";
        if ($content === null) {
            @unlink($file);

            return;
        }
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $content);
    }

    private function manifest(array $files, array $removed = []): void
    {
        file_put_contents("{$this->skeleton}/manifest.json", json_encode(['files' => $files, 'removed' => $removed]));
    }

    private function upgrader(): CmsFileUpgrader
    {
        return new CmsFileUpgrader($this->site, $this->skeleton, $this->state);
    }

    private function statusOf(string $path): ?string
    {
        foreach ($this->upgrader()->report() as $item) {
            if ($item['path'] === $path) {
                return $item['status'];
            }
        }

        return null;
    }

    /** Install release 1 on the site and record its baseline, then ship release 2. */
    private function installThenRelease(array $v1, array $v2): void
    {
        $this->manifest(array_fill_keys(array_keys($v1), []));
        foreach ($v1 as $path => $content) {
            $this->upstream($path, $content);
            $this->siteFile($path, $content);
        }
        $this->upgrader()->applySafe();
        $this->manifest(array_fill_keys(array_keys($v1 + $v2), []));
        foreach ($v2 as $path => $content) {
            $this->upstream($path, $content);
        }
    }

    private const V1 = "line 1\nline 2\nline 3\nline 4\nline 5\nline 6\nline 7\nline 8\n";

    // ── classification ───────────────────────────────────────────────

    public function test_each_file_is_placed_by_comparing_site_baseline_and_release(): void
    {
        $this->installThenRelease(
            ['same.php' => "a\n", 'untouched.php' => "a\n", 'mine.php' => "a\n", 'both.php' => self::V1, 'gone.php' => "a\n"],
            ['untouched.php' => "b\n", 'both.php' => str_replace('line 8', 'line 8 (vela)', self::V1), 'new.php' => "n\n"],
        );
        $this->siteFile('mine.php', "mine\n");
        $this->siteFile('both.php', str_replace('line 1', 'line 1 (site)', self::V1));
        $this->siteFile('gone.php', null);

        $this->assertSame('current', $this->statusOf('same.php'));
        $this->assertSame('update', $this->statusOf('untouched.php'));
        $this->assertSame('customised', $this->statusOf('mine.php'));
        $this->assertSame('review', $this->statusOf('both.php'));
        $this->assertSame('add', $this->statusOf('new.php'));
        $this->assertSame('deleted', $this->statusOf('gone.php'));

        $both = collect($this->upgrader()->report())->firstWhere('path', 'both.php');
        $this->assertTrue($both['merge']);
        $this->assertSame(['overwrite', 'merge', 'keep', 'skip'], $both['actions']);
    }

    public function test_without_a_baseline_a_known_pristine_copy_updates_and_anything_else_waits(): void
    {
        $old = "old pristine\n";
        $this->manifest([
            'changed.php'   => ['known' => [hash('sha256', $old)]],
            'edited.php'    => ['known' => [hash('sha256', $old)]],
            'unchanged.php' => [],
        ]);
        foreach (['changed.php', 'edited.php'] as $p) {
            $this->upstream($p, "new\n");
        }
        $this->upstream('unchanged.php', "same\n");
        $this->siteFile('changed.php', $old);
        $this->siteFile('edited.php', "the site's own\n");
        $this->siteFile('unchanged.php', "the site's own\n");

        $this->assertSame('update', $this->statusOf('changed.php'));
        $this->assertSame('review', $this->statusOf('edited.php'));
        // Not changed this release, so a difference can only be the site's.
        $this->assertSame('customised', $this->statusOf('unchanged.php'));
        // No base copy to merge against.
        $this->assertFalse(collect($this->upgrader()->report())->firstWhere('path', 'edited.php')['merge']);
    }

    public function test_files_the_package_dropped_are_offered_for_deletion_never_deleted_automatically(): void
    {
        $this->manifest([], [
            'public/css/old.css'  => ['known' => [hash('sha256', "old\n")], 'reason' => 'stale'],
            'public/css/edit.css' => ['known' => [hash('sha256', "old\n")]],
            'public/css/none.css' => ['known' => []],
        ]);
        $this->siteFile('public/css/old.css', "old\n");
        $this->siteFile('public/css/edit.css', "changed\n");

        $this->assertSame('remove', $this->statusOf('public/css/old.css'));
        $this->assertSame('removed-customised', $this->statusOf('public/css/edit.css'));
        $this->assertNull($this->statusOf('public/css/none.css'), 'absent files are not reported');

        $this->upgrader()->applySafe();
        $this->assertFileExists("{$this->site}/public/css/old.css");

        $this->assertTrue($this->upgrader()->apply('public/css/old.css', 'delete')['ok']);
        $this->assertFileDoesNotExist("{$this->site}/public/css/old.css");
        $this->assertNotEmpty(glob("{$this->state}/backups/*/public/css/old.css"));

        $this->assertTrue($this->upgrader()->apply('public/css/edit.css', 'keep')['ok']);
        $this->assertSame('kept', $this->statusOf('public/css/edit.css'));
    }

    // ── applying ─────────────────────────────────────────────────────

    public function test_auto_applies_only_safe_changes_and_backs_up_what_it_replaces(): void
    {
        $this->installThenRelease(
            ['untouched.php' => "a\n", 'both.php' => self::V1],
            ['untouched.php' => "b\n", 'both.php' => str_replace('line 8', 'x', self::V1), 'new.php' => "n\n"],
        );
        $siteBoth = str_replace('line 1', 'mine', self::V1);
        $this->siteFile('both.php', $siteBoth);

        $results = $this->upgrader()->applySafe();

        $this->assertEqualsCanonicalizing(['untouched.php', 'new.php'], array_column($results, 'path'));
        $this->assertSame("b\n", file_get_contents("{$this->site}/untouched.php"));
        $this->assertSame("n\n", file_get_contents("{$this->site}/new.php"));
        $this->assertSame($siteBoth, file_get_contents("{$this->site}/both.php"), 'a file needing review is left alone');

        $backups = glob("{$this->state}/backups/*/untouched.php");
        $this->assertCount(1, $backups);
        $this->assertSame("a\n", file_get_contents($backups[0]));

        // The baseline moved with the write: the file is current, not "update".
        $this->assertSame('current', $this->statusOf('untouched.php'));
        $this->assertSame('review', $this->statusOf('both.php'));
        $this->assertFileExists("{$this->state}/actions.log");
    }

    public function test_keep_moves_the_baseline_so_the_file_reads_as_customised(): void
    {
        $this->installThenRelease(['f.php' => self::V1], ['f.php' => str_replace('line 8', 'x', self::V1)]);
        $this->siteFile('f.php', "mine\n");

        $this->assertTrue($this->upgrader()->apply('f.php', 'keep')['ok']);

        $this->assertSame('customised', $this->statusOf('f.php'));
        $this->assertSame("mine\n", file_get_contents("{$this->site}/f.php"));
    }

    public function test_an_action_the_status_does_not_offer_is_refused(): void
    {
        $this->installThenRelease(['f.php' => "a\n"], []);

        $result = $this->upgrader()->apply('f.php', 'merge');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not available', $result['message']);
    }

    // ── merging ──────────────────────────────────────────────────────

    public function test_a_clean_three_way_merge_keeps_both_sides(): void
    {
        $this->installThenRelease(['f.php' => self::V1], ['f.php' => str_replace('line 8', 'line 8 (vela)', self::V1)]);
        $this->siteFile('f.php', str_replace('line 1', 'line 1 (site)', self::V1));

        $result = $this->upgrader()->apply('f.php', 'merge');

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertSame(0, $result['conflicts']);
        $merged = file_get_contents("{$this->site}/f.php");
        $this->assertStringContainsString('line 1 (site)', $merged);
        $this->assertStringContainsString('line 8 (vela)', $merged);
        $this->assertSame('customised', $this->statusOf('f.php'));
    }

    public function test_a_conflicting_merge_writes_nothing_unless_asked_to_write_markers(): void
    {
        $this->installThenRelease(['f.php' => self::V1], ['f.php' => str_replace('line 4', 'vela', self::V1)]);
        $siteVersion = str_replace('line 4', 'site', self::V1);
        $this->siteFile('f.php', $siteVersion);

        $result = $this->upgrader()->apply('f.php', 'merge');
        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame($siteVersion, file_get_contents("{$this->site}/f.php"));

        $result = $this->upgrader()->apply('f.php', 'merge', allowConflicts: true);
        $this->assertTrue($result['ok']);
        $content = file_get_contents("{$this->site}/f.php");
        $this->assertStringContainsString('<<<<<<< site', $content);
        $this->assertStringContainsString('>>>>>>> vela', $content);
    }

    // ── safety ───────────────────────────────────────────────────────

    public function test_manifest_paths_that_could_escape_the_site_are_rejected(): void
    {
        foreach (['../outside.php', '/etc/passwd', 'a/../../b.php', 'a/./b.php'] as $bad) {
            $this->manifest([$bad => []]);
            try {
                $this->upgrader()->manifest();
                $this->fail("accepted {$bad}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid managed path', $e->getMessage());
            }
        }
    }

    public function test_only_manifest_paths_can_be_applied(): void
    {
        $this->installThenRelease(['f.php' => "a\n"], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->upgrader()->apply('.env', 'overwrite');
    }

    public function test_it_will_not_write_through_a_symlink(): void
    {
        $outside = "{$this->root}/outside";
        mkdir($outside);
        $this->manifest(['config/app.php' => ['known' => [hash('sha256', "old\n")]]]);
        $this->upstream('config/app.php', "new\n");
        symlink($outside, "{$this->site}/config");
        file_put_contents("{$outside}/app.php", "old\n");

        $this->expectException(\RuntimeException::class);
        try {
            $this->upgrader()->apply('config/app.php', 'overwrite');
        } finally {
            $this->assertSame("old\n", file_get_contents("{$outside}/app.php"));
        }
    }

    // ── the shipped skeleton ─────────────────────────────────────────

    public function test_the_shipped_manifest_and_skeleton_agree(): void
    {
        $dir = dirname(__DIR__, 2) . '/skeleton';
        $manifest = (new CmsFileUpgrader($this->site, $dir, $this->state))->manifest();

        $shipped = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$dir}/files", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $shipped[] = substr($file->getPathname(), strlen("{$dir}/files/"));
        }
        $sources = array_column($manifest['files'], 'source');

        sort($shipped);
        sort($sources);
        $this->assertSame($shipped, $sources, 'every skeleton file is in the manifest and vice versa');
        $this->assertArrayHasKey('public/index.php', $manifest['files']);
        $this->assertTrue($manifest['files']['public/index.php']['critical']);
        // Stored under another name so it doesn't act on this repo.
        $this->assertSame('gitignore.dist', $manifest['files']['.gitignore']['source']);
    }

    public function test_a_fresh_install_of_the_shipped_skeleton_is_entirely_current(): void
    {
        $dir = dirname(__DIR__, 2) . '/skeleton';
        exec('cp -a ' . escapeshellarg("{$dir}/files/.") . ' ' . escapeshellarg($this->site));
        rename("{$this->site}/gitignore.dist", "{$this->site}/.gitignore");
        rename("{$this->site}/gitattributes.dist", "{$this->site}/.gitattributes");

        $statuses = array_unique(array_column((new CmsFileUpgrader($this->site, $dir, $this->state))->report(), 'status'));

        $this->assertSame(['current'], array_values($statuses));
    }

    // ── command & admin ──────────────────────────────────────────────

    public function test_the_command_reports_as_json_and_applies_with_auto(): void
    {
        $this->installThenRelease(['f.php' => "a\n"], ['f.php' => "b\n"]);
        $this->instance(CmsFileUpgrader::class, $this->upgrader());

        $this->artisan('vela:upgrade-files', ['--json' => true])
            ->expectsOutputToContain('"status": "update"')
            ->assertExitCode(0);
        $this->assertSame("a\n", file_get_contents("{$this->site}/f.php"), 'a report changes nothing');

        $this->artisan('vela:upgrade-files', ['--auto' => true])->assertExitCode(0);
        $this->assertSame("b\n", file_get_contents("{$this->site}/f.php"));
    }

    public function test_strict_fails_when_a_critical_file_needs_review(): void
    {
        $this->manifest(['public/index.php' => ['critical' => true, 'known' => [hash('sha256', "old\n")]]]);
        $this->upstream('public/index.php', "new\n");
        $this->siteFile('public/index.php', "site's\n");
        $this->instance(CmsFileUpgrader::class, $this->upgrader());

        $this->artisan('vela:upgrade-files', ['--strict' => true])->assertExitCode(2);
    }

    private function signInWith(bool $adminRole): void
    {
        $user = $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);
        if ($adminRole) {
            $role = Role::firstOrCreate(['id' => 1], ['title' => 'Admin']);
            $user->roles()->attach($role->id);
        }
    }

    public function test_the_admin_page_is_for_admins_only(): void
    {
        $this->installThenRelease(['f.php' => "a\n"], ['f.php' => "b\n"]);
        $this->instance(CmsFileUpgrader::class, $this->upgrader());

        $this->signInWith(adminRole: false);
        $this->get(route('vela.admin.cms-files.index'))->assertForbidden();
        $this->post(route('vela.admin.cms-files.apply'), ['path' => 'f.php', 'action' => 'overwrite'])->assertForbidden();
        $this->assertSame("a\n", file_get_contents("{$this->site}/f.php"));
    }

    public function test_an_admin_sees_pending_files_and_applies_one(): void
    {
        $this->installThenRelease(['f.php' => "a\n"], ['f.php' => "b\n"]);
        $this->instance(CmsFileUpgrader::class, $this->upgrader());
        $this->signInWith(adminRole: true);

        $this->get(route('vela.admin.cms-files.index'))->assertOk()->assertSee('f.php')->assertSee('Update (safe)');
        $this->get(route('vela.admin.cms-files.diff', ['path' => 'f.php']))->assertOk()->assertSee('+b');
        $this->get(route('vela.admin.cms-files.diff', ['path' => '../../etc/passwd']))->assertNotFound();

        $this->post(route('vela.admin.cms-files.apply'), ['path' => 'f.php', 'action' => 'overwrite'])->assertRedirect();
        $this->assertSame("b\n", file_get_contents("{$this->site}/f.php"));

        $this->post(route('vela.admin.cms-files.apply'), ['path' => '.env', 'action' => 'overwrite'])->assertNotFound();
    }
}
