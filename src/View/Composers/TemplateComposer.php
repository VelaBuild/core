<?php

namespace VelaBuild\Core\View\Composers;

use Illuminate\View\View;
use VelaBuild\Core\Models\Page;

class TemplateComposer
{
    public function compose(View $view): void
    {
        $navPages = Page::where('locale', app()->getLocale())
            ->where('status', 'published')
            ->whereNull('parent_id')
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
    }
}
