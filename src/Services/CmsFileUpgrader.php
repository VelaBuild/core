<?php

namespace VelaBuild\Core\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a site's copy of the starter's files in step with the package.
 *
 * A Vela site is the velabuild/cms starter — a Laravel skeleton plus a few
 * CMS files — with the CMS itself in vendor/velabuild/core. `composer update`
 * brings new core code but leaves the starter's files as they were installed:
 * a fix to public/index.php or bootstrap/app.php never reached a site, and
 * every site was a fork of whatever starter it was born from.
 *
 * The package now carries the pristine starter files it manages
 * (skeleton/files, listed in skeleton/manifest.json). The site records what
 * the package last gave it — a hash and a copy of each file, under
 * storage/app/vela-skeleton — so every file can be placed in one of:
 *
 *   current     the site has exactly the package's version
 *   update      the site never changed it; the package has a new one — safe
 *   add         new in the package, absent from the site — safe
 *   review      changed by the site AND by the package — overwrite, merge or keep
 *   customised  changed by the site only — nothing to do
 *   deleted     the site removed a file it was given — left alone
 *   remove      the package dropped it and the site never changed it — offer delete
 *   removed-customised  the package dropped it but the site changed it — offer delete
 *   kept        the site chose to keep a dropped file
 *
 * Only `update` and `add` are applied automatically. Every write is inside
 * base_path, to a path that comes from the manifest (never from a request),
 * refuses symlinks, and keeps a backup of what it replaced.
 */
class CmsFileUpgrader
{
    public const SAFE = ['update', 'add'];

    public const PENDING = ['update', 'add', 'review', 'remove'];

    private const CACHE_KEY = 'vela:cms-files:pending';

    private const PATH_RE = '#^(?!/)(?!.*(?:^|/)\.\.?(?:/|$))[A-Za-z0-9._/-]{1,200}$#';

    private string $basePath;

    private string $skeletonPath;

    private string $statePath;

    private ?array $manifest = null;

    private ?string $backupStamp = null;

    public function __construct(?string $basePath = null, ?string $skeletonPath = null, ?string $statePath = null)
    {
        $this->basePath = rtrim($basePath ?? base_path(), '/');
        $this->skeletonPath = rtrim($skeletonPath ?? dirname(__DIR__, 2) . '/skeleton', '/');
        $this->statePath = rtrim($statePath ?? storage_path('app/vela-skeleton'), '/');
    }

    // ── What the package ships ──────────────────────────────────────────

    /**
     * @return array{files: array<string, array>, removed: array<string, array>}
     */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $raw = json_decode((string) @file_get_contents($this->skeletonPath . '/manifest.json'), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('skeleton manifest missing or unreadable');
        }

        $files = [];
        foreach ($raw['files'] ?? [] as $path => $entry) {
            $this->assertManagedPath($path);
            $source = $entry['source'] ?? $path;
            $this->assertManagedPath($source);
            $files[$path] = [
                'source'   => $source,
                'strategy' => ($entry['strategy'] ?? 'merge') === 'replace' ? 'replace' : 'merge',
                'critical' => (bool) ($entry['critical'] ?? false),
                'known'    => array_values(array_filter((array) ($entry['known'] ?? []), 'is_string')),
            ];
        }

        $removed = [];
        foreach ($raw['removed'] ?? [] as $path => $entry) {
            $this->assertManagedPath($path);
            $removed[$path] = [
                'known'  => array_values(array_filter((array) ($entry['known'] ?? []), 'is_string')),
                'reason' => (string) ($entry['reason'] ?? ''),
            ];
        }

        return $this->manifest = ['files' => $files, 'removed' => $removed];
    }

    public function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('velabuild/core')) {
            return (string) InstalledVersions::getPrettyVersion('velabuild/core');
        }

        return 'dev';
    }

    // ── Report ──────────────────────────────────────────────────────────

    /**
     * Every managed file with its status and what can be done about it.
     *
     * @param  string[]  $only  restrict to these paths (or path prefixes ending in '/')
     * @return array<int, array{path: string, status: string, critical: bool, strategy: string, safe: bool, merge: bool, actions: string[], reason?: string}>
     */
    public function report(array $only = []): array
    {
        $state = $this->state();
        $items = [];

        foreach ($this->manifest()['files'] as $path => $entry) {
            if (!$this->matches($path, $only)) {
                continue;
            }
            if (!is_file($this->upstreamPath($entry))) {
                // A broken package build, not something the site can fix.
                Log::warning('vela.cms-files: manifest entry has no skeleton file', ['path' => $path]);
                continue;
            }
            $items[] = $this->classify($path, $entry, $state);
        }

        foreach ($this->manifest()['removed'] as $path => $entry) {
            if (!$this->matches($path, $only) || !is_file($this->sitePath($path))) {
                continue;
            }
            $siteSha = hash_file('sha256', $this->sitePath($path));
            if (($state['kept'][$path] ?? null) === $siteSha) {
                $status = 'kept';
            } elseif (in_array($siteSha, $entry['known'], true) || ($state['files'][$path] ?? null) === $siteSha) {
                $status = 'remove';
            } else {
                $status = 'removed-customised';
            }
            $items[] = [
                'path' => $path, 'status' => $status, 'critical' => false, 'strategy' => 'replace',
                'safe' => false, 'merge' => false, 'actions' => $status === 'kept' ? ['delete'] : ['delete', 'keep', 'skip'],
                'reason' => $entry['reason'],
            ];
        }

        return $items;
    }

    /** How many files want attention — cheap enough for every admin page (cached). */
    public function pendingCount(): int
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            try {
                return count(array_filter($this->report(), fn ($i) => in_array($i['status'], self::PENDING, true)));
            } catch (\Throwable $e) {
                return 0;
            }
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Whether this user may change the site's own files: an Admin who can edit settings. */
    public static function userMayManage($user): bool
    {
        if (!$user || !Gate::forUser($user)->allows('config_edit')) {
            return false;
        }

        return method_exists($user, 'roles')
            && $user->roles()->where(function ($q) {
                $q->where('vela_roles.id', 1)->orWhere('vela_roles.title', 'Admin');
            })->exists();
    }

    private function classify(string $path, array $entry, array $state): array
    {
        $upstreamSha = hash_file('sha256', $this->upstreamPath($entry));
        $site = $this->sitePath($path);
        $siteSha = is_file($site) ? hash_file('sha256', $site) : null;
        $baseSha = $state['files'][$path] ?? null;
        $hasBaseCopy = $baseSha !== null && is_file($this->basePath($path))
            && hash_file('sha256', $this->basePath($path)) === $baseSha;

        if ($siteSha === null) {
            $status = $baseSha !== null ? 'deleted' : 'add';
        } elseif ($siteSha === $upstreamSha) {
            $status = 'current';
        } elseif ($baseSha !== null) {
            $status = match (true) {
                $siteSha === $baseSha     => 'update',
                $upstreamSha === $baseSha => 'customised',
                default                   => 'review',
            };
        } elseif (in_array($siteSha, $entry['known'], true)) {
            // No baseline yet (installed before this existed), but it is a
            // pristine copy of an earlier release.
            $status = 'update';
        } else {
            // No baseline and not a known pristine copy. A file with no
            // `known` hashes has not changed since baselines began, so the
            // site's version differs from what it was given: customised.
            $status = $entry['known'] === [] ? 'customised' : 'review';
        }

        $merge = $status === 'review' && $entry['strategy'] === 'merge' && $hasBaseCopy;
        $actions = match ($status) {
            'add'        => ['add', 'skip'],
            'update'     => ['overwrite', 'keep', 'skip'],
            'review'     => array_values(array_filter(['overwrite', $merge ? 'merge' : null, 'keep', 'skip'])),
            'customised' => ['overwrite'],
            'deleted'    => ['add'],
            default      => [],
        };

        return [
            'path'     => $path,
            'status'   => $status,
            'critical' => $entry['critical'],
            'strategy' => $entry['strategy'],
            'safe'     => in_array($status, self::SAFE, true),
            'merge'    => $merge,
            'actions'  => $actions,
        ];
    }

    // ── Apply ───────────────────────────────────────────────────────────

    /**
     * Apply every safe action (update, add) and record a baseline for files
     * that already match, so later releases can tell what the site changed.
     *
     * @return array<int, array{path: string, action: string, ok: bool, message?: string}>
     */
    public function applySafe(array $only = [], ?string $actor = null): array
    {
        $results = [];
        foreach ($this->report($only) as $item) {
            if ($item['status'] === 'current') {
                $this->recordBaseline($item['path']);
            } elseif ($item['safe']) {
                $results[] = $this->apply($item['path'], $item['status'] === 'add' ? 'add' : 'overwrite', $actor);
            }
        }

        return $results;
    }

    /**
     * @param  string  $action  overwrite | add | merge | keep | skip | delete
     * @return array{path: string, action: string, ok: bool, message?: string, conflicts?: int, backup?: string}
     */
    public function apply(string $path, string $action, ?string $actor = null, bool $allowConflicts = false): array
    {
        $manifest = $this->manifest();
        $isFile = isset($manifest['files'][$path]);
        $isRemoved = isset($manifest['removed'][$path]);
        if (!$isFile && !$isRemoved) {
            // Only manifest paths, ever: a request can name a file, not choose one.
            throw new \InvalidArgumentException("not a managed file: {$path}");
        }

        $item = collect($this->report([$path]))->firstWhere('path', $path);
        if ($item === null || !in_array($action, $item['actions'], true)) {
            return $this->result($path, $action, false, $actor, ['message' => "'{$action}' is not available for {$path} (" . ($item['status'] ?? 'not present') . ')']);
        }

        $result = match ($action) {
            'overwrite', 'add' => $this->writeUpstream($path),
            'merge'            => $this->merge($path, $allowConflicts),
            'keep'             => $this->keep($path, $isRemoved),
            'delete'           => $this->delete($path),
            'skip'             => ['ok' => true],
        };

        self::forget();

        return $this->result($path, $action, $result['ok'], $actor, $result);
    }

    private function writeUpstream(string $path): array
    {
        $entry = $this->manifest()['files'][$path];
        $backup = $this->backup($path);
        $this->writeSiteFile($path, (string) file_get_contents($this->upstreamPath($entry)), fileperms($this->upstreamPath($entry)) & 0777);
        $this->recordBaseline($path);

        return ['ok' => true, 'backup' => $backup];
    }

    private function merge(string $path, bool $allowConflicts): array
    {
        $entry = $this->manifest()['files'][$path];
        $merged = $this->threeWay(
            (string) file_get_contents($this->sitePath($path)),
            (string) file_get_contents($this->basePath($path)),
            (string) file_get_contents($this->upstreamPath($entry)),
        );
        if ($merged === null) {
            return ['ok' => false, 'message' => 'three-way merge is unavailable (git not found)'];
        }
        [$content, $conflicts] = $merged;
        if ($conflicts > 0 && !$allowConflicts) {
            return ['ok' => false, 'conflicts' => $conflicts,
                'message' => "{$conflicts} conflicting hunk(s); nothing written. Merge with conflict markers explicitly, overwrite, or keep yours."];
        }

        $backup = $this->backup($path);
        $this->writeSiteFile($path, $content, fileperms($this->sitePath($path)) & 0777);
        // The site now holds this release's changes; its own edits are what
        // remain different from the baseline.
        $this->recordBaseline($path);

        return ['ok' => true, 'backup' => $backup, 'conflicts' => $conflicts];
    }

    private function keep(string $path, bool $isRemoved): array
    {
        $state = $this->state();
        if ($isRemoved) {
            $state['kept'][$path] = hash_file('sha256', $this->sitePath($path));
            $this->saveState($state);
        } else {
            // "I've seen this release's version and want mine": the baseline
            // moves to it, so the file reads as customised until the next one.
            $this->recordBaseline($path);
        }

        return ['ok' => true];
    }

    private function delete(string $path): array
    {
        $backup = $this->backup($path);
        $this->assertWritable($path);
        unlink($this->sitePath($path));
        $state = $this->state();
        unset($state['files'][$path], $state['kept'][$path]);
        $this->saveState($state);

        return ['ok' => true, 'backup' => $backup];
    }

    // ── Diff & merge ────────────────────────────────────────────────────

    /** Unified diff from the site's file to the package's version. */
    public function diff(string $path): string
    {
        $manifest = $this->manifest();
        if (!isset($manifest['files'][$path]) && !isset($manifest['removed'][$path])) {
            throw new \InvalidArgumentException("not a managed file: {$path}");
        }
        $site = is_file($this->sitePath($path)) ? (string) file_get_contents($this->sitePath($path)) : '';
        $upstream = isset($manifest['files'][$path]) ? (string) file_get_contents($this->upstreamPath($manifest['files'][$path])) : '';

        return $this->unifiedDiff($site, $upstream, "site/{$path}", "vela/{$path}");
    }

    /**
     * @return array{0: string, 1: int}|null  merged content and conflict count, or null without git
     */
    public function threeWay(string $mine, string $base, string $theirs): ?array
    {
        // Trivial cases need no tool.
        if ($mine === $base) {
            return [$theirs, 0];
        }
        if ($theirs === $base || $theirs === $mine) {
            return [$mine, 0];
        }

        $dir = $this->tempDir();
        try {
            file_put_contents("{$dir}/mine", $mine);
            file_put_contents("{$dir}/base", $base);
            file_put_contents("{$dir}/theirs", $theirs);
            $out = $this->runProcess(['git', 'merge-file', '-p', '-L', 'site', '-L', 'base', '-L', 'vela ' . $this->version(),
                "{$dir}/mine", "{$dir}/base", "{$dir}/theirs"]);
            // Exit status is the number of conflicts; negative (>127 here) is an error.
            if ($out === null || $out['code'] > 127) {
                return null;
            }

            return [$out['stdout'], $out['code']];
        } finally {
            $this->removeDir($dir);
        }
    }

    private function unifiedDiff(string $a, string $b, string $labelA, string $labelB): string
    {
        if ($a === $b) {
            return '';
        }
        $dir = $this->tempDir();
        try {
            file_put_contents("{$dir}/a", $a);
            file_put_contents("{$dir}/b", $b);
            $out = $this->runProcess(['git', 'diff', '--no-index', '--no-color', '-U3', "{$dir}/a", "{$dir}/b"]);
            if ($out !== null && $out['code'] <= 1 && $out['stdout'] !== '') {
                $lines = explode("\n", $out['stdout']);
                // Drop git's header (diff --git / index / --- / +++) for our own labels.
                while ($lines && !str_starts_with($lines[0], '@@')) {
                    array_shift($lines);
                }

                return "--- {$labelA}\n+++ {$labelB}\n" . implode("\n", $lines);
            }
        } finally {
            $this->removeDir($dir);
        }

        // Without git: one hunk covering everything between the common prefix and suffix.
        $x = explode("\n", $a);
        $y = explode("\n", $b);
        $start = 0;
        while ($start < count($x) && $start < count($y) && $x[$start] === $y[$start]) {
            $start++;
        }
        $endX = count($x);
        $endY = count($y);
        while ($endX > $start && $endY > $start && $x[$endX - 1] === $y[$endY - 1]) {
            $endX--;
            $endY--;
        }
        $body = array_merge(
            array_map(fn ($l) => '-' . $l, array_slice($x, $start, $endX - $start)),
            array_map(fn ($l) => '+' . $l, array_slice($y, $start, $endY - $start)),
        );

        return "--- {$labelA}\n+++ {$labelB}\n@@ -" . ($start + 1) . ',' . ($endX - $start) . ' +' . ($start + 1) . ',' . ($endY - $start) . " @@\n" . implode("\n", $body) . "\n";
    }

    // ── State, paths, IO ────────────────────────────────────────────────

    private function state(): array
    {
        $state = json_decode((string) @file_get_contents($this->statePath . '/state.json'), true);

        return is_array($state) ? $state + ['files' => [], 'kept' => []] : ['files' => [], 'kept' => []];
    }

    private function saveState(array $state): void
    {
        $state['version'] = $this->version();
        $state['updated_at'] = date('c');
        $this->ensureDir($this->statePath);
        $tmp = $this->statePath . '/.state.json.' . getmypid();
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        rename($tmp, $this->statePath . '/state.json');
    }

    /** The package's current version of $path becomes what the site was last given. */
    private function recordBaseline(string $path): void
    {
        $entry = $this->manifest()['files'][$path];
        $content = (string) file_get_contents($this->upstreamPath($entry));
        $copy = $this->basePath($path);
        $this->ensureDir(dirname($copy));
        file_put_contents($copy, $content);

        $state = $this->state();
        $state['files'][$path] = hash('sha256', $content);
        $this->saveState($state);
    }

    private function backup(string $path): ?string
    {
        $site = $this->sitePath($path);
        if (!is_file($site)) {
            return null;
        }
        $this->backupStamp ??= date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $dest = "{$this->statePath}/backups/{$this->backupStamp}/{$path}";
        $this->ensureDir(dirname($dest));
        copy($site, $dest);

        return $dest;
    }

    private function writeSiteFile(string $path, string $content, int $mode): void
    {
        $this->assertWritable($path);
        $target = $this->sitePath($path);
        $this->ensureDir(dirname($target));
        $tmp = dirname($target) . '/.' . basename($target) . '.vela-tmp';
        file_put_contents($tmp, $content);
        @chmod($tmp, $mode ?: 0644);
        rename($tmp, $target);
    }

    /** Refuse anything that would write outside base_path or through a symlink. */
    private function assertWritable(string $path): void
    {
        $this->assertManagedPath($path);
        $base = realpath($this->basePath) ?: $this->basePath;
        $current = $this->basePath;
        foreach (explode('/', $path) as $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new \RuntimeException("refusing to write through a symlink: {$path}");
            }
        }
        $parent = realpath(dirname($this->sitePath($path)));
        if ($parent !== false && $parent !== $base && !str_starts_with($parent, $base . '/')) {
            throw new \RuntimeException("refusing to write outside the site: {$path}");
        }
    }

    private function assertManagedPath(string $path): void
    {
        if (!preg_match(self::PATH_RE, $path)) {
            throw new \InvalidArgumentException("invalid managed path: {$path}");
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function matches(string $path, array $only): bool
    {
        if ($only === []) {
            return true;
        }
        foreach ($only as $filter) {
            if ($path === $filter || (str_ends_with($filter, '/') && str_starts_with($path, $filter))) {
                return true;
            }
        }

        return false;
    }

    private function sitePath(string $path): string
    {
        return $this->basePath . '/' . $path;
    }

    private function basePath(string $path): string
    {
        return $this->statePath . '/base/' . $path;
    }

    private function upstreamPath(array $entry): string
    {
        return $this->skeletonPath . '/files/' . $entry['source'];
    }

    private function result(string $path, string $action, bool $ok, ?string $actor, array $extra): array
    {
        $result = ['path' => $path, 'action' => $action, 'ok' => $ok] + array_diff_key($extra, ['ok' => true]);
        $entry = $result + ['actor' => $actor, 'version' => $this->version(), 'at' => date('c')];
        Log::info('vela.cms-files', $entry);
        $this->ensureDir($this->statePath);
        @file_put_contents($this->statePath . '/actions.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        return $result;
    }

    /** @return array{code: int, stdout: string}|null */
    private function runProcess(array $cmd): ?array
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        // 127: command not found (proc_open runs via execvp, so no shell message).
        return $code === 127 ? null : ['code' => $code, 'stdout' => (string) $stdout];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/vela-merge-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
