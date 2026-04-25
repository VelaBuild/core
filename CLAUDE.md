# CLAUDE.md

## Project
- **VelaBuild Core** — AI-native CMS built on Laravel 10+
- PHP 8.1+, Blade templates, Bootstrap 4 + CoreUI (admin), Vela Design System
- Package installed via Composer, publishes assets to `public/vendor/vela/`

## Image System
The image optimization system is in `src/Helpers/image.php`. Key functions:

- `vela_image($src, $alt, $sizes, $mode, $attrs, $loading)` — Generates responsive `<img>` with WebP srcset
  - `$loading` parameter controls behaviour:
    - `'lazy'` (default) — `loading="lazy"` + `sizes="auto"`. Use for all below-fold images.
    - `'eager'` — No loading attribute. Use when lazy isn't appropriate but no preload needed.
    - `'preload'` — `loading="eager"` + `fetchpriority="high"`. Use for LCP/hero images. Pair with `vela_image_preload()` in `<head>`.
    - `false` — Legacy compat, same as eager.
  - All existing callers use the default (lazy) and don't need changes.
  - The 6th parameter was added as optional — fully backwards compatible.

- `vela_image_preload($src, $sizes, $mode)` — Returns `<link rel="preload" as="image">` for `<head>`.
- `vela_image_url($src, $width, $height, $mode)` — Returns optimized URL without wrapping in `<img>`.
- `vela_optimize_imgs($html)` — Rewrites raw `<img>` tags in HTML blobs through the optimizer.

Usage in Blade templates:
```blade
{{-- Below fold (default — lazy + sizes=auto) --}}
{!! vela_image($url, 'Alt text', [400, 800, 1200]) !!}

{{-- Hero / LCP image --}}
@push('head')
{!! vela_image_preload($heroUrl, [640, 1280, 1920]) !!}
@endpush
{!! vela_image($heroUrl, 'Hero', [640, 1280, 1920], 'fit', [], 'preload') !!}

{{-- Skip lazy loading --}}
{!! vela_image($url, 'Alt', [400, 800], 'fit', [], 'eager') !!}
```

## Public Content API
- Endpoints at `/api/content/` — read-only, no auth required
- Toggle: `VELA_PUBLIC_API=true` in .env or admin Settings > MCP
- Endpoints: `/pages`, `/pages/{slug}`, `/posts`, `/posts/{slug}`, `/categories`, `/search?q=`

## MCP Server
- Authenticated API at `/api/mcp/` — requires bearer token
- Configure in admin Settings > MCP

## Agent Discovery
- `/.well-known/api-catalog` — RFC 9727 API catalog
- `/.well-known/mcp/server-card.json` — MCP Server Card
- `/.well-known/agent-skills/index.json` — Agent Skills Discovery
- `AgentDiscovery` middleware adds Link headers + supports Accept: text/markdown
- `robots.txt` includes Content Signals (configurable in Settings > Visibility)

## Cloudflare Integration

`CloudflareService` (`src/Services/Tools/CloudflareService.php`) supports:
- Cache purge: `purgeUrls()`, `purgeByTags()`, `purgeAll()`
- Web performance: `getEarlyHints/setEarlyHints`, `getPolish/setPolish`, `getMirage/setMirage`
- `getSpeedSettings()` returns combined status of all three in one call
- Zone status, SSL, page rules, cache level queries

## MCP Browser Rendering

Cloudflare Browser Rendering Workers can be used for:
- Screenshot capture for AI image-to-site workflows
- Server-side rendering for SEO previews
- PDF generation from pages

Configure via `CLOUDFLARE_BROWSER_RENDERING_URL` in .env.

## Key Directories
- `src/Http/Controllers/Admin/` — Admin panel controllers
- `src/Http/Controllers/Public/` — Public-facing controllers
- `src/Http/Controllers/Api/` — API controllers (MCP + Content API)
- `src/Services/` — Business logic services
- `src/Helpers/` — Helper functions (image.php, cache_tag.php)
- `resources/views/layouts/admin.blade.php` — Admin layout
- `resources/views/templates/` — Public site templates
- `config/vela.php` — Main configuration
- `public/css/vela-admin.css` — Admin design system CSS

## Testing
- `composer test` to run tests
- Tests in `tests/Feature/` and `tests/Unit/`

## Conventions
- Settings stored in `vela_configs` table via `VelaConfig::updateOrCreate()`
- Menu items registered via `Vela::registerMenu()`
- Blocks registered via `Vela::registerBlock()`
- Templates in `resources/views/templates/{name}/`
- Lang files in `resources/lang/{locale}/`
