<?php

namespace VelaBuild\Core\Services;

/**
 * The layout a new theme starts life with.
 *
 * Asked to write a theme from an empty file, a build produced forty-five
 * lines of crude CSS and a body containing nothing but @yield('content') —
 * no header, no navigation, no footer, cards that were not cards. It knew
 * what the design looked like; it could not write four hundred lines of
 * stylesheet inside a handful of turns.
 *
 * So it starts from this instead: a complete, working frame with a rule for
 * every block the site can render, all of it driven by the tokens at the top.
 * Choosing those tokens from a design is something it does well, and doing
 * only that turns writing a theme from an essay into a decision.
 */
class ThemeSkeleton
{
    /**
     * The design decisions a theme is made of, with sober defaults.
     *
     * Every one is a value a design states plainly and a model can read off
     * an image: what the type is, what the colours are, how round the corners
     * are, how wide the page runs.
     */
    public const TOKENS = [
        'font-display' => ['Georgia, "Times New Roman", serif', 'Headings and the site name.'],
        'font-body' => ['system-ui, -apple-system, "Segoe UI", sans-serif', 'Body copy, navigation, buttons.'],
        'page-width' => ['1200px', 'How wide the content runs before it stops growing.'],
        'radius' => ['4px', 'Corner rounding on cards and buttons. 0 for square, 999px for pills.'],
        'bg' => ['#ffffff', 'The page behind everything.'],
        'surface' => ['#f7f7f7', 'Cards and panels that sit on the page.'],
        'ink' => ['#1a1a1a', 'Body text and headings.'],
        'muted' => ['#666666', 'Secondary text: dates, captions, descriptions.'],
        'line' => ['#e2e2e2', 'Borders and rules.'],
        'accent' => ['#1a1a1a', 'Buttons, prices, links, anything that should catch the eye.'],
        'accent-ink' => ['#ffffff', 'Text on top of the accent colour.'],
        'band' => ['#1a1a1a', 'Full-width bands: the stats strip, the quote, the footer.'],
        'band-ink' => ['#ffffff', 'Text on top of a band.'],
        'bar' => ['#1a1a1a', 'The thin strip above the header. Same as the band unless the design differs.'],
        'bar-ink' => ['#ffffff', 'Text in that strip.'],
        'bar-display' => ['none', 'Set to "block" if the design has a thin strip above the header.'],
        'bar-text' => ['""', 'What that strip says, in quotes: "Open Tue-Sun - 5pm till late".'],
        'bar-text-right' => ['""', 'What sits at its right-hand end, in quotes. Empty for nothing.'],
        'section-gap' => ['72px', 'Vertical breathing room between sections.'],
    ];

    /**
     * A complete layout: the frame, the navigation, the footer, and a
     * stylesheet covering every block, all of it driven by the tokens.
     */
    public function layout(): string
    {
        $tokens = [];
        foreach (self::TOKENS as $name => [$default, $note]) {
            $tokens[] = '            --' . $name . ': ' . $default . ';';
        }
        $tokenBlock = implode("\n", $tokens);

        return <<<BLADE
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('vela::templates._partials.meta-seo')
    @include('vela::templates._partials.meta-opengraph')
    <style>
        :root {
{$tokenBlock}
        }

        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: var(--font-body);
            line-height: 1.6;
        }
        h1, h2, h3, h4, h5, .site-name { font-family: var(--font-display); line-height: 1.1; margin: 0 0 .5em; }
        a { color: inherit; }
        img { max-width: 100%; height: auto; display: block; }

        .wrap { max-width: var(--page-width); margin: 0 auto; padding: 0 24px; }

        /* ── the strip above the header ─────────────────────────────── */
        .top-bar {
            display: var(--bar-display);
            background: var(--bar); color: var(--bar-ink);
            font-size: 12px; letter-spacing: .16em; text-transform: uppercase;
            padding: 10px 0;
        }
        .top-bar .wrap { display: flex; justify-content: space-between; gap: 16px; }
        .top-bar .wrap::before { content: var(--bar-text); }
        .top-bar .wrap::after { content: var(--bar-text-right); }

        /* ── header ─────────────────────────────────────────────────── */
        .site-header { border-bottom: 1px solid var(--line); background: var(--bg); }
        .site-header .wrap { display: flex; align-items: center; justify-content: space-between; gap: 24px; padding-top: 22px; padding-bottom: 22px; }
        .site-name { font-size: 28px; margin: 0; text-decoration: none; }
        .site-nav { display: flex; gap: 28px; font-size: 13px; letter-spacing: .08em; text-transform: uppercase; }
        .site-nav a { text-decoration: none; }
        .site-nav a:hover { color: var(--accent); }

        /* ── footer ─────────────────────────────────────────────────── */
        .site-footer { background: var(--band); color: var(--band-ink); margin-top: var(--section-gap); padding: 56px 0 32px; }
        .site-footer .wrap { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 40px; }
        .site-footer h4 { font-size: 22px; }
        .site-footer a { text-decoration: none; display: block; line-height: 2; opacity: .85; }
        .site-footer .fine { grid-column: 1 / -1; border-top: 1px solid rgba(255,255,255,.15); margin-top: 24px; padding-top: 20px; font-size: 12px; opacity: .7; }

        /* ── blocks: hero ───────────────────────────────────────────── */
        .block-hero { position: relative; background-size: cover; background-position: center; }
        .block-hero-overlay { position: absolute; inset: 0; }
        .block-hero-inner { position: relative; max-width: var(--page-width); margin: 0 auto; padding: 96px 24px; }
        .block-hero-title { font-size: 56px; margin-bottom: 16px; }
        .block-hero-subtitle { font-size: 18px; color: var(--muted); max-width: 44ch; margin-bottom: 28px; }
        .block-hero-actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .block-hero-btn {
            display: inline-block; padding: 14px 28px; text-decoration: none;
            font-size: 13px; letter-spacing: .08em; text-transform: uppercase;
            border-radius: var(--radius);
        }
        .block-hero-btn-primary { background: var(--accent); color: var(--accent-ink); }
        .block-hero-btn-secondary { border: 2px solid currentColor; }

        /* ── blocks: the figures strip ──────────────────────────────── */
        .block-icon-boxes {
            background: var(--band); color: var(--band-ink);
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px; text-align: center; padding: 40px 24px;
        }
        .icon-box-icon { display: none; }
        .icon-box-title { font-family: var(--font-display); font-size: 34px; margin-bottom: 4px; }
        .icon-box-description { font-size: 11px; letter-spacing: .18em; text-transform: uppercase; opacity: .85; }

        /* ── blocks: priced cards ───────────────────────────────────── */
        .block-pricing-tiers {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px; max-width: var(--page-width); margin: 0 auto; padding: var(--section-gap) 24px;
        }
        .block-pricing-tier {
            background: var(--bg); border: 1px solid var(--line); border-radius: var(--radius);
            padding: 24px; display: flex; flex-direction: column;
        }
        .block-pricing-tier-label { font-family: var(--font-display); font-size: 21px; letter-spacing: 0; text-transform: none; margin-bottom: 8px; }
        .block-pricing-tier-desc { color: var(--muted); font-size: 14px; margin-bottom: 16px; }
        .block-pricing-tier-price { font-family: var(--font-display); color: var(--accent); display: flex; align-items: baseline; gap: 2px; margin-top: auto; }
        .block-pricing-tier-price-num { font-size: 30px; font-weight: 700; }
        .block-pricing-tier-cta { display: none; }

        /* ── blocks: the quote ──────────────────────────────────────── */
        .block-testimonials { background: var(--band); color: var(--band-ink); padding: var(--section-gap) 24px; }
        .testimonial-card { max-width: 760px; margin: 0 auto; text-align: center; background: none; border: 0; }
        .testimonial-quote-icon { display: none; }
        .testimonial-quote { font-family: var(--font-display); font-size: 28px; font-style: italic; line-height: 1.4; margin-bottom: 18px; }
        .testimonial-name { font-size: 12px; letter-spacing: .18em; text-transform: uppercase; opacity: .85; }

        /* ── blocks: grids of articles and topics ───────────────────── */
        .block-posts-grid, .block-categories-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px; max-width: var(--page-width); margin: 0 auto; padding: var(--section-gap) 24px;
        }
        .post-card, .category-card {
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
            overflow: hidden; text-decoration: none;
        }
        .post-card h3, .category-card h3 { font-size: 19px; padding: 16px 16px 0; }
        .post-card p, .category-card p { color: var(--muted); font-size: 14px; padding: 0 16px 16px; margin: .5em 0 0; }

        /* ── blocks: call to action, text, pictures ─────────────────── */
        .block-cta { background: var(--band); color: var(--band-ink); padding: var(--section-gap) 24px; text-align: center; }
        .block-cta-heading { font-size: 36px; }
        .block-cta-actions { display: flex; gap: 12px; justify-content: center; margin-top: 24px; }
        .block-cta-btn { display: inline-block; padding: 14px 28px; text-decoration: none; border-radius: var(--radius); }
        .block-cta-btn-primary { background: var(--accent); color: var(--accent-ink); }
        .block-cta-btn-secondary { border: 2px solid currentColor; }
        .block-text { max-width: var(--page-width); margin: 0 auto; padding: 24px; }
        .block-image { max-width: var(--page-width); margin: 0 auto; padding: 24px; }

        @media (max-width: 720px) {
            .site-header .wrap { flex-direction: column; align-items: flex-start; gap: 12px; }
            .site-footer .wrap { grid-template-columns: 1fr; }
            .block-hero-title { font-size: 36px; }
            .block-hero-inner { padding: 56px 24px; }
            .top-bar .wrap { flex-direction: column; gap: 4px; }
        }
    </style>
</head>
<body>
    <div class="top-bar"><div class="wrap"></div></div>

    <header class="site-header">
        <div class="wrap">
            <a class="site-name" href="{{ route('vela.public.home') }}">{{ config('app.name') }}</a>
            <nav class="site-nav">
                <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
                <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
            </nav>
        </div>
    </header>

    @yield('content')

    <footer class="site-footer">
        <div class="wrap">
            <div>
                <h4>{{ config('app.name') }}</h4>
                <p>{{ config('vela.site.description') }}</p>
            </div>
            <div>
                <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
                <a href="{{ route('vela.public.posts.index') }}">{{ __('vela::public.articles') }}</a>
                <a href="{{ route('vela.public.categories.index') }}">{{ __('vela::public.topics') }}</a>
            </div>
            <div></div>
            <div class="fine">&copy; {{ date('Y') }} {{ config('app.name') }}</div>
        </div>
    </footer>
</body>
</html>
BLADE;
    }

    /**
     * The page view: a wrapper and the editable rows, nothing more.
     */
    public function page(): string
    {
        return <<<'BLADE'
@extends(vela_template_layout())

@section('title', $page->meta_title ?: $page->title)
@if($page->meta_description)
@section('description', $page->meta_description)
@endif

@section('content')
<div class="page-content page-slug-{{ $page->slug }} page-id-{{ $page->id }}">
    @if($page->slug !== 'home')
        <div class="wrap" style="padding-top:48px"><h1>{{ $page->title }}</h1></div>
    @endif

    @include('vela::templates._partials.page-rows', ['page' => $page])
</div>

@if($page->custom_css)<style>{!! $page->custom_css !!}</style>@endif
@if($page->custom_js)<script>{!! $page->custom_js !!}</script>@endif
@endsection
BLADE;
    }

    /**
     * The tokens, described, for whoever is choosing their values.
     */
    public function tokenReference(): string
    {
        $lines = [];

        foreach (self::TOKENS as $name => [$default, $note]) {
            $lines[] = '  --' . $name . ' (now ' . ($default === '' ? 'empty' : $default) . ') — ' . $note;
        }

        return implode("\n", $lines);
    }
}
