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
