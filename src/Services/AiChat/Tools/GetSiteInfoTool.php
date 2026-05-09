<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\SiteContext;

/**
 * Read-only "who is this site" lookup. Lets the chatbot answer questions
 * like "what's the site name?", "what is this site about?", "what locale
 * are we in?" with one tool call instead of trying to extract the
 * fragments out of its system-prompt context window.
 *
 * No parameters — always returns the same shape so the model doesn't
 * need to think about which fields to ask for.
 */
class GetSiteInfoTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $ctx = app(SiteContext::class);

        $defaultLocale = '';
        $supportedLocales = [];
        try {
            $defaultLocale = (string) LaravelLocalization::getDefaultLocale();
            foreach (LaravelLocalization::getSupportedLocales() as $code => $meta) {
                $supportedLocales[] = $code;
            }
        } catch (\Throwable $e) {
            $defaultLocale = (string) (config('app.locale') ?: 'en');
        }

        // Active public template (theme) — useful when the user asks
        // "what theme is this site using?".
        $activeTemplate = (string) (config('vela.template.active') ?: '');

        return [
            'success'           => true,
            'name'              => $ctx->getName(),
            'niche'             => $ctx->getNiche(),
            'description'       => $ctx->getSiteDescription(),
            'tagline'           => (string) (config('vela.site.tagline') ?: ''),
            'short_summary'     => $ctx->getDescription(), // single-sentence summary used in prompts
            'url'               => (string) (config('app.url') ?: ''),
            'default_locale'    => $defaultLocale,
            'supported_locales' => $supportedLocales,
            'active_template'   => $activeTemplate,
        ];
    }
}
