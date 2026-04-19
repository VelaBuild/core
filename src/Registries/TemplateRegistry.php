<?php

namespace VelaBuild\Core\Registries;

class TemplateRegistry
{
    protected array $templates = [];

    public function register(string $name, array $config): void
    {
        $this->templates[$name] = array_merge([
            'label' => $name,
            'namespace' => $name,
            'path' => null,
            'description' => '',
            'screenshot' => '',
            'category' => '',
            'options' => [],
        ], $config);

        if (isset($config['path']) && $config['path']) {
            app('view')->addNamespace($config['namespace'], $config['path']);
        }
    }

    /**
     * Scan a directory of templates and auto-register each sub-directory.
     *
     * Each sub-directory is treated as a template. Metadata is read from an
     * optional `template.json` file; if absent, sensible defaults are derived
     * from the directory name. Entries already registered (by an explicit
     * `register()` call earlier) are skipped so explicit registration wins.
     *
     * @param string $baseDir Absolute path to the directory containing template sub-dirs.
     */
    public function autoDiscover(string $baseDir): void
    {
        if (! is_dir($baseDir)) {
            return;
        }

        foreach (scandir($baseDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '_')) {
                continue;
            }
            $path = $baseDir . DIRECTORY_SEPARATOR . $entry;
            if (! is_dir($path)) {
                continue;
            }
            if (isset($this->templates[$entry])) {
                continue;
            }

            $config = ['path' => $path];
            $manifest = $path . DIRECTORY_SEPARATOR . 'template.json';
            if (is_file($manifest)) {
                $decoded = json_decode((string) file_get_contents($manifest), true);
                if (is_array($decoded)) {
                    $config = array_merge($config, $decoded);
                }
            }
            $config['label']     = $config['label']     ?? ucwords(str_replace(['-', '_'], ' ', $entry));
            $config['namespace'] = $config['namespace'] ?? 'vela-' . $entry;

            $this->register($entry, $config);
        }
    }

    public function get(string $name): ?array
    {
        return $this->templates[$name] ?? null;
    }

    public function all(): array
    {
        return $this->templates;
    }

    public function has(string $name): bool
    {
        return isset($this->templates[$name]);
    }
}
