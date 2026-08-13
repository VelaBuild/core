<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;

class UpdateTemplateColorsTool extends BaseTool
{
    /**
     * The three colours that reach the page, and the config keys that carry
     * them. Anything written under `css_*` — which is what this tool used to
     * do — is read by nothing at all: the value was stored, the tool reported
     * success, and the site looked exactly the same.
     */
    private const ROLES = [
        'primary'    => 'theme_primary_color',
        'secondary'  => 'theme_secondary_color',
        'background' => 'theme_background_color',
    ];

    /** Templates whose stylesheets read the --vela-* properties these set. */
    private const TEMPLATES_THAT_USE_THEM = ['minimal', 'corporate'];

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $colors = $parameters['colors'] ?? [];

        if (empty($colors)) {
            return ['error' => 'Colors parameter is required'];
        }

        $colors = $this->toKnownRoles($colors);
        if ($colors === []) {
            return [
                'error' => 'This tool sets the site\'s primary, secondary and background colours and nothing else. '
                    . 'Name one of those three. Anything more specific — a block, a heading, one section — is done '
                    . 'with update_custom_css against the block class.',
                'valid_colors' => array_keys(self::ROLES),
            ];
        }

        $previousState = [];
        foreach ($colors as $role => $value) {
            $configKey = self::ROLES[$role];
            $current = VelaConfig::where('key', $configKey)->first();
            $previousState[$role] = [
                'key' => $configKey,
                'value' => $current?->value,
                'existed' => $current !== null,
            ];
        }

        if ($actionLog) {
            $actionLog->update(['previous_state' => $previousState]);
        }

        foreach ($colors as $role => $value) {
            VelaConfig::updateOrCreate(['key' => self::ROLES[$role]], ['value' => $value]);
        }
        $this->refreshSiteConfigCache();

        $result = [
            'success' => true,
            'updated' => array_keys($colors),
        ];

        // Only some templates read these. On the others the value is stored
        // and shown in the admin, and a visitor sees no difference — say so
        // rather than letting it be reported as a change to the site.
        $template = (string) config('vela.template.active', 'default');
        if (!in_array($template, self::TEMPLATES_THAT_USE_THEM, true)) {
            $result['warning'] = "Saved, but the '{$template}' template does not read these colours, so the site looks "
                . 'exactly the same to a visitor. Tell the user that plainly. To actually recolour this template, use '
                . 'update_custom_css against the block classes (get_page_blocks lists them), or switch to a template '
                . 'that follows the site colours: ' . implode(', ', self::TEMPLATES_THAT_USE_THEM) . '.';
        }

        return $result;
    }

    /**
     * Map whatever the model called the colour onto the three roles that
     * exist. It reaches for palette names it saw elsewhere — "brand", "accent"
     * — which used to be stored verbatim under a key nothing reads.
     */
    private function toKnownRoles(array $colors): array
    {
        $aliases = [
            'brand' => 'primary', 'accent' => 'primary', 'main' => 'primary',
            'primary_color' => 'primary', '--primary-color' => 'primary',
            'secondary_color' => 'secondary', '--secondary-color' => 'secondary',
            'bg' => 'background', 'background_color' => 'background', '--background-color' => 'background',
        ];

        $mapped = [];
        foreach ($colors as $name => $value) {
            $key = strtolower(trim((string) $name));
            $role = self::ROLES[$key] ?? null ? $key : ($aliases[$key] ?? null);
            if ($role !== null && is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', trim($value))) {
                $mapped[$role] = trim($value);
            }
        }

        return $mapped;
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state) {
            throw new \RuntimeException('No previous state to restore.');
        }

        foreach ($state as $var => $prev) {
            if ($prev['existed']) {
                VelaConfig::updateOrCreate(['key' => $prev['key']], ['value' => $prev['value']]);
            } else {
                VelaConfig::where('key', $prev['key'])->delete();
            }
        }

        $this->refreshSiteConfigCache();
    }
}
