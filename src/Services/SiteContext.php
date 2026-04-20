<?php
namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\VelaConfig;

class SiteContext
{
    public function getName(): string
    {
        return $this->getConfig('site_name', 'app.name', 'Vela CMS');
    }

    public function getNiche(): string
    {
        return $this->getConfig('site_niche', 'vela.ai.site_context.niche', 'general');
    }

    public function getSiteDescription(): string
    {
        return $this->getConfig('site_description', 'vela.ai.site_context.description', '');
    }

    public function getDescription(): string
    {
        $name = $this->getName();
        $niche = $this->getNiche();
        $desc = $this->getSiteDescription();

        $parts = [];
        if ($niche && $niche !== 'general') {
            $parts[] = "a {$niche} website";
        } else {
            $parts[] = 'a website';
        }
        if ($name) {
            $parts[0] .= " called '{$name}'";
        }
        if ($desc) {
            $parts[] = $desc;
        }
        return implode('. ', $parts);
    }

    private function getConfig(string $velaConfigKey, string $configPath, string $default = ''): string
    {
        try {
            $dbValue = VelaConfig::where('key', $velaConfigKey)->value('value');
            if ($dbValue !== null && $dbValue !== '') {
                return $dbValue;
            }
        } catch (\Throwable $e) {
            // DB unavailable (e.g. no-DB static cache deploy) — fall through to config file.
        }
        return config($configPath, $default);
    }
}
