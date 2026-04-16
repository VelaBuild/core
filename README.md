# Vela Core

The core package for [Vela.build](https://vela.build) — a high-performance, standards-compliant, AI-friendly, extensible CMS built on Laravel.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## About

This is the **core package** — it contains all the CMS logic, models, controllers, AI services, and admin panel. It is installed into Laravel projects as a Composer dependency.

**Want to get started quickly?** Use the [Vela CMS starter project](https://github.com/VelaBuild/cms) instead — it gives you a pre-configured Laravel application with Vela Core already wired up. Clone it once, customise it, and receive core updates via `composer update`.

Vela Core provides a full-featured content management system, page builder, and admin panel as a Laravel package. It's designed from the ground up to integrate AI capabilities natively — not as an afterthought — while remaining fast, extensible, and standards-compliant.

## Features

- **Page Builder** — Flexible block-based page builder with rows, blocks, widgets, and templates
- **AI-Native** — Built-in support for OpenAI, Claude, and Gemini for content generation, image generation, and AI-assisted workflows
- **Admin Panel** — Complete admin interface with authentication, roles, permissions, and 2FA
- **Media Management** — Integrated media library with image optimization and cache management (via Spatie Media Library)
- **Multilingual** — Full i18n support with 10 languages out of the box and translation management tools
- **SEO & Analytics** — Google Analytics, Search Console, and PageSpeed Insights integration
- **Static Site Generation** — Generate static HTML and serve cached pages without booting Laravel
- **Extensible Registries** — Register custom blocks, menus, templates, widgets, and tools via the `Vela` facade
- **Cloudflare Integration** — Automatic cache purging and CDN management
- **PWA Support** — Icon generation and progressive web app configuration
- **Figma Export** — Design-to-code pipeline with Figma integration
- **Queue-Ready** — 14 async jobs for AI generation, content processing, translations, and more

## Requirements

- PHP 8.1+
- Laravel 10 or 11

## Installation

```bash
composer require velabuild/core
```

The package auto-discovers its service provider and facade. Run the install command to publish assets and run migrations:

```bash
php artisan vela:install
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="VelaBuild\Core\VelaServiceProvider" --tag="config"
```

Configuration options include route prefixes, middleware stacks, AI provider settings, template selection, and multilingual setup. See the published config files for full details.

## Static File Serving

Vela can generate pre-rendered HTML for public pages via `php artisan vela:generate-static`. When a matching static file exists, the page is served directly without booting Laravel — dramatically reducing response times and server load.

If you're using the [CMS starter project](https://github.com/VelaBuild/cms), this is already configured in `public/index.php`. If you're integrating Vela Core into an existing Laravel app, add the static file front-controller to the top of your `public/index.php` (before the Laravel bootstrap). It works by:

1. Reading `VELA_CACHE` from `.env` directly (lightweight file parse, no framework needed)
2. Mapping GET request URIs to pre-rendered HTML files in `resources/static/`
3. Serving the static file with cache headers and exiting — Laravel never boots
4. Falling through to normal Laravel bootstrap when no static file matches

Admin, auth, and API routes are automatically excluded. Static files are regenerated on content changes or manually via `php artisan vela:generate-static`. Set `VELA_CACHE=false` in `.env` to disable.

See the [CMS starter's `public/index.php`](https://github.com/VelaBuild/cms/blob/main/public/index.php) for the reference implementation.

## Usage

### Registering Custom Blocks

```php
use VelaBuild\Core\Facades\Vela;

Vela::registerBlock('my-block', MyBlockClass::class);
```

### Registering Templates

```php
Vela::registerTemplate('my-template', MyTemplateClass::class);
```

### Artisan Commands

Vela ships with CLI commands for common tasks:

| Command | Description |
|---|---|
| `vela:install` | Initial setup and migration |
| `vela:app-init` | Initialize application configuration |
| `vela:app-build` | Build application assets |
| `vela:create-content` | Scaffold new content types |
| `vela:import-content` | Import content from external sources |
| `vela:generate-image` | AI image generation |
| `vela:ai-wizard` | Interactive AI content assistant |
| `vela:generate-static` | Generate static site files |
| `vela:theme-check` | Validate theme configuration |
| `vela:cleanup-image-cache` | Purge optimized image cache |

Run `php artisan list vela` for the full list.

## Testing

```bash
composer test
```

Or directly with PHPUnit:

```bash
vendor/bin/phpunit
```

## Contributing

Contributions are welcome! Please read our [Contributor License Agreement](CLA.md) before submitting a pull request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -m 'Add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a pull request

By submitting a pull request, you agree to the terms of our [CLA](CLA.md).

## Security

If you discover a security vulnerability, please email [m@awcode.com](mailto:m@awcode.com) instead of opening a public issue.

## License

Vela Core is open-source software licensed under the [MIT License](LICENSE).

## Links

- [Website](https://vela.build)
- [CMS Starter Project](https://github.com/VelaBuild/cms)
- [Documentation](https://vela.build/docs)
