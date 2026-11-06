<?php

namespace VelaBuild\Core\Services\SitePack;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loads a SitePacker archive's rows into this site's (empty) database.
 *
 * 1. Run only the migrations the packed site had run, so every table has the
 *    shape its rows were written in.
 * 2. Replace each table's rows with the packed ones (columns the table no
 *    longer has are dropped, with a note).
 * 3. The caller then runs `migrate`: anything newer runs on the real data,
 *    exactly as it would have on the original site.
 *
 * Links to the packed site's own address (http://localhost:8000/storage/…)
 * become root-relative, so they work on whatever domain the site gets.
 */
class DataImporter
{
    private const CHUNK = 200;

    private string $from = '';

    public function __construct(private Migrator $migrator)
    {
    }

    /** @return array{migrations: int, missing_migrations: string[], tables: array<string, int>, skipped: array<string, string>} */
    public function import(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $manifest = json_decode((string) @file_get_contents($dir . '/vela-manifest.json'), true);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== SitePacker::FORMAT) {
            throw new \RuntimeException('not a Vela site archive (vela-manifest.json missing or wrong format)');
        }
        $url = (string) ($manifest['app_url'] ?? '');
        $this->from = preg_match('#^https?://[^\s"\'<>]+$#', $url) ? $url : '';

        [$ran, $missing] = $this->migrateAsPacked((array) ($manifest['migrations'] ?? []));

        $report = ['migrations' => $ran, 'missing_migrations' => $missing, 'tables' => [], 'skipped' => []];
        Schema::disableForeignKeyConstraints();
        try {
            foreach ((array) ($manifest['tables'] ?? []) as $table => $_) {
                $file = "{$dir}/data/{$table}.jsonl";
                if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $table) || SitePacker::skipTable($table)) {
                    continue;
                }
                if (!Schema::hasTable($table)) {
                    $report['skipped'][$table] = 'no such table here (made outside migrations?)';
                    continue;
                }
                if (!is_file($file)) {
                    $report['skipped'][$table] = 'rows missing from the archive';
                    continue;
                }
                [$rows, $dropped] = $this->load($table, $file);
                $report['tables'][$table] = $rows;
                if ($dropped) {
                    $report['skipped']["{$table} columns"] = implode(', ', $dropped);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $report;
    }

    /** @return array{0: int, 1: string[]} */
    private function migrateAsPacked(array $names): array
    {
        $paths = array_merge($this->migrator->paths(), [database_path('migrations')]);
        $available = $this->migrator->getMigrationFiles($paths);
        $files = [];
        $missing = [];
        foreach ($names as $name) {
            if (isset($available[$name])) {
                $files[] = $available[$name];
            } else {
                $missing[] = (string) $name;
            }
        }
        if (!$this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }
        $this->migrator->run($files);

        return [count($files), $missing];
    }

    /** @return array{0: int, 1: string[]} rows loaded, columns dropped */
    private function load(string $table, string $file): array
    {
        $columns = array_flip(Schema::getColumnListing($table));
        DB::table($table)->delete();
        $in = fopen($file, 'rb');
        $batch = [];
        $rows = 0;
        $dropped = [];
        while (($line = fgets($in)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($row as $column => $value) {
                if (!isset($columns[$column])) {
                    $dropped[$column] = true;
                    continue;
                }
                $clean[$column] = $this->decode($value);
            }
            $batch[] = $clean;
            if (count($batch) >= self::CHUNK) {
                $rows += $this->insert($table, $batch);
            }
        }
        fclose($in);
        $rows += $this->insert($table, $batch);

        return [$rows, array_keys($dropped)];
    }

    private function insert(string $table, array &$batch): int
    {
        $n = count($batch);
        if ($n) {
            // Rows in one insert must share columns; group by shape.
            foreach (collect($batch)->groupBy(fn ($r) => implode(',', array_keys($r))) as $group) {
                DB::table($table)->insert($group->all());
            }
        }
        $batch = [];

        return $n;
    }

    private function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            return isset($value['$b64']) ? base64_decode((string) $value['$b64']) : json_encode($value);
        }

        return is_string($value) ? $this->rewrite($value) : $value;
    }

    private function rewrite(string $value): string
    {
        if ($this->from === '' || !str_contains($value, (string) parse_url($this->from, PHP_URL_HOST))) {
            return $value;
        }
        // Serialized PHP records string lengths; changing one breaks the value.
        if (preg_match('/^(?:[aOs]:\d+:|[bid]:)/', $value) && @unserialize($value, ['allowed_classes' => false]) !== false) {
            return $value;
        }
        $escaped = str_replace('/', '\/', $this->from);

        return str_replace([$this->from . '/', $escaped . '\/'], ['/', '\/'], $value);
    }
}
