<?php

namespace VelaBuild\Core\View\Composers;

use Illuminate\View\View;
use VelaBuild\Core\Models\Page;

class TemplateComposer
{
    public function compose(View $view): void
    {
        // When holding page is active, hide navigation for non-admin visitors
        $holdingPageActive = config('vela.visibility.mode') === 'restricted'
            && config('vela.visibility.holding_page')
            && !auth('vela')->check();

        $navPages = $holdingPageActive
            ? collect()
            : Page::where('locale', app()->getLocale())
                ->where('status', 'published')
                ->whereNull('parent_id')
                ->where('slug', '!=', 'home')
                ->orderBy('order_column')
                ->get();

        $currentLocale = app()->getLocale();
        $flagMap = [
            'en' => 'gb', 'th' => 'th', 'zh-Hans' => 'cn', 'de' => 'de',
            'nl' => 'nl', 'fr' => 'fr', 'it' => 'it', 'dk' => 'dk',
            'ru' => 'ru', 'ar' => 'sa',
        ];

        $view->with('navPages', $navPages);
        $view->with('currentLocale', $currentLocale);
        $view->with('flagMap', $flagMap);
        $view->with('holdingPageActive', $holdingPageActive);
    }
}
