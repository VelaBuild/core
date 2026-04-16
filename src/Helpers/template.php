<?php

if (!function_exists('vela_template_view')) {
    function vela_template_view(string $name): string
    {
        $template = config('vela.template.active', 'default');
        $hostOwnsTheme = is_dir(resource_path("views/templates/{$template}"));

        // 1. Check host templates (resources/views/templates/{template}/{name})
        if (view()->exists("templates.{$template}.{$name}")) {
            return "templates.{$template}.{$name}";
        }

        // 2. Fall back to host default template
        if ($template !== 'default' && view()->exists("templates.default.{$name}")) {
            return "templates.default.{$name}";
        }

        // If the host owns the theme, don't fall through to package templates
        // (missing views are intentional)
        if (!$hostOwnsTheme) {
            // 3. Check package templates
            if (view()->exists("vela::templates.{$template}.{$name}")) {
                return "vela::templates.{$template}.{$name}";
            }

            // 4. Fall back to package default template
            if ($template !== 'default' && view()->exists("vela::templates.default.{$name}")) {
                return "vela::templates.default.{$name}";
            }
        }

        // 5. Final fallback to public views in package
        $fallbacks = [
            'home'             => 'vela::public.home',
            'articles'         => 'vela::public.posts.index',
            'article'          => 'vela::public.posts.show',
            'page'             => 'vela::public.pages.show',
            'categories_index' => 'vela::public.categories.index',
            'categories_show'  => 'vela::public.categories.show',
        ];

        return $fallbacks[$name] ?? $name;
    }
}

if (!function_exists('vela_template_layout')) {
    function vela_template_layout(): string
    {
        $template = config('vela.template.active', 'default');

        // Host templates first
        if (view()->exists("templates.{$template}.layout")) {
            return "templates.{$template}.layout";
        }

        // Package templates
        if (view()->exists("vela::templates.{$template}.layout")) {
            return "vela::templates.{$template}.layout";
        }

        return 'vela::templates.default.layout';
    }
}

if (!function_exists('template_layout')) {
    function template_layout(): string
    {
        return vela_template_layout();
    }
}

if (!function_exists('template_view')) {
    function template_view(string $name): string
    {
        return vela_template_view($name);
    }
}
