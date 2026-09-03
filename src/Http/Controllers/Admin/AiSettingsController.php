<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Services\AiSettingsService;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AiSettingsController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $settings = app(AiSettingsService::class);

        return view('vela::admin.ai-settings', [
            'status' => $settings->getStatus(),
        ]);
    }

    public function update(Request $request)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $settings = app(AiSettingsService::class);

        // Save API keys (only if not env-locked and a value was provided)
        foreach (['openai', 'anthropic', 'gemini'] as $provider) {
            $field = $provider . '_api_key';
            if (!$settings->isEnvLocked($field) && $request->has($field)) {
                $val = $request->input($field);
                // Empty string = clear, null = skip, "unchanged" = skip
                if ($val === '') {
                    $settings->set($field, null);
                } elseif ($val !== null && $val !== 'unchanged') {
                    $settings->set($field, $val);
                }
            }
        }

        // Save provider selections (only if not env-locked)
        if (!$settings->isEnvLocked('chat_provider') && $request->has('chat_provider')) {
            $settings->set('chat_provider', $request->input('chat_provider'));
        }
        if (!$settings->isEnvLocked('image_provider') && $request->has('image_provider')) {
            $settings->set('image_provider', $request->input('image_provider'));
        }
        if (!$settings->isEnvLocked('openai_image_model') && $request->has('openai_image_model')) {
            $model = $request->input('openai_image_model');
            if (in_array($model, ['gpt-image-1', 'gpt-image-1.5', 'gpt-image-2'], true)) {
                $settings->set('openai_image_model', $model);
            }
        }

        // Anthropic only, and only for an identity-linked key: without it every
        // call is refused, including the one that would have told the operator
        // the key works.
        if (!$settings->isEnvLocked('anthropic_workspace_id') && $request->has('anthropic_workspace_id')) {
            $workspace = trim((string) $request->input('anthropic_workspace_id'));

            if ($workspace !== '' && !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $workspace)) {
                return redirect()->back()->withErrors([
                    'anthropic_workspace_id' => 'A workspace id looks like wrkspc_0123abc. Copy it from the '
                        . 'workspace page in the Anthropic console.',
                ]);
            }

            $settings->set('anthropic_workspace_id', $workspace === '' ? null : $workspace);
        }

        // The model each provider thinks with. Free text rather than a menu:
        // a list baked into a release cannot name the model that comes out
        // after it. Blank clears the choice and puts the provider back on
        // whatever this Vela ships with.
        foreach (['openai', 'anthropic', 'gemini'] as $provider) {
            $field = $provider . '_model';

            if ($settings->isEnvLocked($field) || !$request->has($field)) {
                continue;
            }

            $model = trim((string) $request->input($field));

            // The menu's escape hatch. Read only when the menu asks for it, so
            // a value left in the box by an earlier choice cannot leak into
            // the save, and "Other" with nothing typed clears the setting
            // rather than storing the marker.
            if ($model === '__other') {
                $model = trim((string) $request->input($field . '_other'));
            }

            // Model ids are a narrow shape, and anything else here would be
            // sent straight to the provider as part of a URL or a request body.

            if ($model !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $model)) {
                return redirect()->back()->withErrors([
                    $field => 'That does not look like a model id. Use the name the provider gives it, such as '
                        . implode(' or ', array_slice(AiSettingsService::MODEL_SUGGESTIONS[$provider] ?? [], 0, 2)) . '.',
                ]);
            }

            $settings->set($field, $model === '' ? null : $model);
        }

        // Native web search toggle. Hidden 0 + checkbox 1 pattern means a
        // valid submit ALWAYS carries native_search. We only write when the
        // field is actually present so a partial-form / API update doesn't
        // silently flip the default-on behaviour to off.
        if ($request->has('native_search')) {
            $settings->set('native_search', $request->boolean('native_search') ? '1' : '0');
        }

        return redirect()->back()->with('message', __('vela::global.ai_settings_saved'));
    }

    /**
     * JSON endpoint for chatbot to check status.
     */
    public function status()
    {
        abort_if(Gate::denies('ai_chat_access'), Response::HTTP_FORBIDDEN);

        $settings = app(AiSettingsService::class);

        return response()->json([
            'configured' => $settings->getStatus()['has_text_provider'],
            'settings_url' => route('vela.admin.ai-settings.index'),
        ]);
    }
}
