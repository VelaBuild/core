<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Registries\SiteSettingsRegistry;
use VelaBuild\Core\Models\VelaConfig;

class UpdateSiteConfigTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $key = $parameters['key'] ?? null;
        $value = $parameters['value'] ?? null;

        if (!$key) {
            return ['error' => 'Key parameter is required'];
        }

        $current = VelaConfig::where('key', $key)->first();

        // A key nothing has ever stored is almost always invented: the user
        // asked for a feature, the model guessed a plausible name, and the row
        // it writes is read by nothing. Refuse by default so the answer becomes
        // "this site has no such setting" instead of a write plus a caveat the
        // user is asked to go and verify.
        //
        // Settings the site genuinely has are the exception. A fresh install
        // stores a row only for what someone has already saved, so site_name —
        // offered in Settings, and mapped onto app.name for every template —
        // had no row and could not be written at all. A site built by the
        // design builder was stuck being called Vela CMS because of it.
        if ($current === null && !SiteSettingsRegistry::knows($key) && empty($parameters['create_new'])) {
            return [
                'error' => "There is no site setting called '{$key}' — writing it would store a value nothing reads, "
                    . 'so the site would not change. Check whether the feature exists (search_files / list_routes / '
                    . 'get_site_config with no key) and tell the user plainly if it does not. Only if you have '
                    . 'confirmed that some code reads this exact key, resend with create_new: true.',
                'existing_keys' => VelaConfig::orderBy('key')->pluck('key')->all(),
            ];
        }

        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'key' => $key,
                    'value' => $current?->value,
                    'existed' => $current !== null,
                ],
            ]);
        }

        VelaConfig::updateOrCreate(['key' => $key], ['value' => $value]);
        $this->refreshSiteConfigCache();

        $result = [
            'success' => true,
            'key' => $key,
            'value' => $value,
        ];

        // Reached only with create_new, i.e. the caller says it checked. Keep
        // the caveat so it still cannot be summarised as a visible change.
        if ($current === null && !SiteSettingsRegistry::knows($key)) {
            $result['warning'] = "'{$key}' did not exist before this write. Confirm the change is actually visible "
                . 'before telling the user it is done.';
        }

        return $result;
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state) {
            throw new \RuntimeException('No previous state to restore.');
        }

        $key = $state['key'];

        if ($state['existed']) {
            VelaConfig::updateOrCreate(['key' => $key], ['value' => $state['value']]);
        } else {
            VelaConfig::where('key', $key)->delete();
        }

        $this->refreshSiteConfigCache();
    }
}
