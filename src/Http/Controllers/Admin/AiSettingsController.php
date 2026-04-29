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
