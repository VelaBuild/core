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
        'hero-ink' => ['#ffffff', 'Text in the hero. It sits over an image behind a dark film, so white unless the design says otherwise.'],
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
    {{-- The block stylesheet every shipped theme pulls in the same way. It
         carries what the block markup expects and a theme cannot reasonably
         restate: the row grid — page-rows writes the track widths inline and
         takes display:grid from here, so without it a two-column row stacks —
         along with list, quote, table and caption styling for the text and
         image blocks. It comes before the rules below so the theme can still
         overrule any of it. --}}
    @velaAssets('public')
    {{-- Several blocks are Alpine components. Without it their x-show panels
         never hide: the gallery block's lightbox, for one, renders as a black
         sheet over the whole page with a close button that does nothing. --}}
    <script defer src="https://unpkg.com/alpinejs@3.14.9/dist/cdn.min.js"></script>
    {{-- Where update_custom_css and a page's own CSS land. A theme without
         this include makes every one of those calls a silent no-op. --}}
    @include('vela::templates._partials.custom-css')
    @stack('head')
    <style>
        :root {
{$tokenBlock}

            /* The block stylesheet above paints from its own variables, set
               for a light page. Pointed at the theme's colours instead, every
               rule in it follows the design rather than fighting it. */
            --block-accent: var(--accent);
            --block-accent-hover: var(--accent);
            --block-text-primary: var(--ink);
            --block-text-secondary: var(--ink);
            --block-text-muted: var(--muted);
            --block-border: var(--line);
            --block-bg-light: var(--surface);
            --block-bg-hover: var(--surface);
            --block-bg-white: var(--bg);
            --block-form-border: var(--line);
        }

        *, *::before, *::after { box-sizing: border-box; }
        /* Held back until Alpine has hidden it, so a panel that starts closed
           is never shown for the moment before the script runs. */
        [x-cloak] { display: none !important; }
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
        .block-hero { position: relative; background-size: cover; background-position: center; color: var(--hero-ink); }
        .block-hero-overlay { position: absolute; inset: 0; }
        .block-hero-inner { position: relative; max-width: var(--page-width); margin: 0 auto; padding: 96px 24px; }
        .block-hero-title { font-size: 56px; margin-bottom: 16px; }
        /* inline-block, so the hero's own text-align moves this box as well
           as the words in it. As a block it kept a width and hugged the left,
           and choosing centre or right in the editor appeared to do nothing. */
        .block-hero-subtitle {
            display: inline-block; text-align: inherit;
            font-size: 18px; max-width: 44ch; margin-bottom: 28px;
            color: inherit; opacity: .9;
        }
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
        /* A band sets its own text colour; the block stylesheet paints some
           of these directly, which would otherwise win over inheriting it. */
        .block-icon-boxes *, .block-testimonials *, .block-cta * { color: inherit; }
        .icon-box-icon { display: none; }
        .icon-box-title { font-family: var(--font-display); font-size: 34px; margin-bottom: 4px; color: inherit; }
        .icon-box-description { font-size: 11px; letter-spacing: .18em; text-transform: uppercase; opacity: .85; color: inherit; }

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
        .block-pricing-tier-badge {
            display: inline-block; padding: 4px 10px; margin-bottom: 10px; border-radius: 999px;
            background: var(--accent); color: var(--accent-ink);
            font-size: 11px; letter-spacing: .12em; text-transform: uppercase;
        }
        .block-pricing-tier-headline { font-family: var(--font-display); font-size: 19px; margin: 0 0 .25em; }
        .block-pricing-tier-price-cur { font-size: 16px; vertical-align: super; }
        .block-pricing-tier-price-period { color: var(--muted); font-size: 14px; }
        .block-pricing-tier-price-note { color: var(--muted); font-size: 13px; }
        .block-pricing-tier-features-cap {
            font-size: 11px; letter-spacing: .14em; text-transform: uppercase;
            color: var(--muted); margin: 16px 0 8px;
        }
        .block-pricing-tier-features { list-style: none; margin: 0; padding: 0; font-size: 14px; }
        .block-pricing-tier-features li { padding: 5px 0; border-top: 1px solid var(--line); }

        /* ── blocks: the quote ──────────────────────────────────────── */
        .block-testimonials { background: var(--band); color: var(--band-ink); padding: var(--section-gap) 24px; }
        .testimonial-card { max-width: 760px; margin: 0 auto; text-align: center; background: none; border: 0; }
        .testimonial-quote-icon { display: none; }
        .testimonial-quote { font-family: var(--font-display); font-size: 28px; font-style: italic; line-height: 1.4; margin-bottom: 18px; color: inherit; }
        .testimonial-name { font-size: 12px; letter-spacing: .18em; text-transform: uppercase; opacity: .85; color: inherit; }
        .testimonial-author { font-size: 12px; letter-spacing: .18em; text-transform: uppercase; opacity: .85; }
        .testimonial-title { font-size: 12px; color: var(--muted); }

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
        .block-cta-heading { font-size: 36px; color: inherit; }
        .block-cta-actions { display: flex; gap: 12px; justify-content: center; margin-top: 24px; }
        .block-cta-btn { display: inline-block; padding: 14px 28px; text-decoration: none; border-radius: var(--radius); }
        .block-cta-btn-primary { background: var(--accent); color: var(--accent-ink); }
        .block-cta-btn-secondary { border: 2px solid currentColor; }
        .block-cta-inner { max-width: var(--page-width); margin: 0 auto; }
        .block-cta-description { opacity: .85; margin: .5em auto 0; max-width: 620px; }
        .block-cta-note { font-size: 13px; opacity: .7; margin-top: 14px; }
        .block-text { max-width: var(--page-width); margin: 0 auto; padding: 24px; }
        .block-image { max-width: var(--page-width); margin: 0 auto; padding: 24px; }
        .block-video { max-width: var(--page-width); margin: 0 auto; padding: 24px; }
        .block-html { max-width: var(--page-width); margin: 0 auto; padding: 24px; }
        .block-delimiter { max-width: var(--page-width); margin: var(--section-gap) auto; border-top: 1px solid var(--line); }
        .block-checklist { max-width: var(--page-width); margin: 0 auto; padding: 24px; list-style: none; }
        .checklist-item { display: flex; gap: 10px; align-items: flex-start; margin: .5em 0; }
        .checklist-checkbox { accent-color: var(--accent); margin-top: .25em; }
        .block-warning {
            max-width: var(--page-width); margin: 24px auto; padding: 18px 20px;
            background: var(--surface); border-left: 4px solid var(--accent); border-radius: var(--radius);
        }
        .block-warning-title { font-family: var(--font-display); margin: 0 0 .25em; }
        .block-warning-message { color: var(--muted); margin: 0; }

        /* ── blocks: questions and answers ──────────────────────────── */
        .block-accordion { max-width: 860px; margin: 0 auto; padding: var(--section-gap) 24px; }
        .block-accordion-item { border-bottom: 1px solid var(--line); }
        .block-accordion-header {
            display: flex; justify-content: space-between; align-items: center; gap: 16px; width: 100%;
            padding: 20px 0; background: none; border: 0; cursor: pointer; text-align: left;
            font-family: var(--font-display); font-size: 19px; color: inherit;
        }
        .block-accordion-chevron { flex: none; transition: transform .2s; }
        .block-accordion-body { padding: 0 0 20px; color: var(--muted); }

        /* ── blocks: a form someone fills in ────────────────────────── */
        .block-contact-form {
            max-width: 640px; margin: 0 auto; padding: var(--section-gap) 24px; text-align: center;
        }
        .block-contact-form-title { font-family: var(--font-display); font-size: 32px; margin: 0 0 .25em; }
        .block-contact-form-intro { color: var(--muted); margin: 0 0 24px; }
        .block-contact-form .form-group { margin-bottom: 14px; text-align: left; }
        .block-contact-form label { display: block; font-size: 13px; margin-bottom: 6px; }
        .block-contact-form input, .block-contact-form textarea, .block-contact-form select {
            width: 100%; padding: 12px 14px; font: inherit; color: inherit;
            background: var(--bg); border: 1px solid var(--line); border-radius: var(--radius);
        }
        .block-contact-form button[type="submit"] {
            padding: 14px 28px; border: 0; border-radius: var(--radius); cursor: pointer;
            background: var(--accent); color: var(--accent-ink); font: inherit;
        }
        .form-success { color: var(--accent); }
        .form-error { color: #b3261e; font-size: 13px; }
        /* A field only a robot fills in. It must never be seen or reachable. */
        .honeypot { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

        /* ── blocks: pictures side by side, and one at a time ───────── */
        .block-gallery { max-width: var(--page-width); margin: 0 auto; padding: var(--section-gap) 24px; }
        .gallery-grid { display: grid; gap: 10px; }
        .gallery-item { border-radius: var(--radius); overflow: hidden; }
        .gallery-caption { color: var(--muted); font-size: 13px; padding-top: 6px; }
        .block-carousel { position: relative; max-width: var(--page-width); margin: 0 auto; padding: var(--section-gap) 24px; }
        .carousel-track { display: flex; overflow: hidden; border-radius: var(--radius); }
        .carousel-slide { flex: 0 0 100%; }
        .carousel-caption { color: var(--muted); font-size: 13px; padding-top: 8px; text-align: center; }
        .carousel-arrow {
            position: absolute; top: 50%; transform: translateY(-50%);
            background: var(--band); color: var(--band-ink); border: 0; cursor: pointer;
            width: 40px; height: 40px; border-radius: 999px; opacity: .8;
        }
        .carousel-prev { left: 32px; }
        .carousel-next { right: 32px; }
        .carousel-dots { display: flex; gap: 8px; justify-content: center; padding-top: 14px; }
        .carousel-dot { width: 8px; height: 8px; border-radius: 999px; border: 0; padding: 0; cursor: pointer; background: var(--line); }

        /* ── the views that are not the homepage ───────────────────── */
        .page-head { padding: var(--section-gap) 24px 0; }
        .page-head h1 { font-size: 42px; }
        .page-head h2 { font-size: 30px; }
        .page-head time { color: var(--muted); font-size: 14px; }
        .card-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px; padding: 32px 24px var(--section-gap);
        }
        .card {
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
            overflow: hidden; text-decoration: none; display: block;
        }
        .card .card-body { padding: 16px; }
        .card h3 { font-size: 19px; margin-bottom: .4em; }
        .card p { color: var(--muted); font-size: 14px; margin: 0 0 .6em; }
        .card time { color: var(--muted); font-size: 12px; }
        .article { padding-bottom: var(--section-gap); }
        .prose { max-width: 68ch; margin: 32px 0; }
        .prose img { margin: 24px 0; border-radius: var(--radius); }
        .prose h2, .prose h3 { margin-top: 1.4em; }
        .prose blockquote { border-left: 3px solid var(--accent); margin: 24px 0; padding-left: 20px; font-style: italic; }
        .prose blockquote cite { display: block; margin-top: 8px; font-size: 13px; font-style: normal; color: var(--muted); }
        .prose figure { margin: 24px 0; }
        .prose figcaption { color: var(--muted); font-size: 13px; text-align: center; margin-top: 8px; }
        .prose pre { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; overflow-x: auto; }
        .prose hr { border: 0; border-top: 1px solid var(--line); margin: 32px 0; }
        .prose .table-scroll { overflow-x: auto; margin: 24px 0; }
        .prose table { border-collapse: collapse; width: 100%; font-size: 14px; }
        .prose th, .prose td { border: 1px solid var(--line); padding: 8px 12px; text-align: left; vertical-align: top; }
        .prose th { background: var(--surface); }
        .pager { display: flex; justify-content: space-between; gap: 16px; padding-bottom: var(--section-gap); }
        .pager a { text-decoration: none; border: 1px solid var(--line); border-radius: var(--radius); padding: 10px 18px; }

        @media (max-width: 720px) {
            .site-header .wrap { flex-direction: column; align-items: flex-start; gap: 12px; }
            .site-footer .wrap { grid-template-columns: 1fr; }
            .block-hero-title { font-size: 36px; }
            .block-hero-inner { padding: 56px 24px; }
            .top-bar .wrap { flex-direction: column; gap: 4px; }
            .page-head h1 { font-size: 30px; }

            /* A line of text is a fine link for a cursor and a poor one for a
               thumb: the navigation came out 21px tall, well under the 44px a
               touch target is expected to be. The padding is what grows, so
               the type stays the size the design asked for. */
            .site-nav { gap: 8px; flex-wrap: wrap; }
            .site-nav a,
            .site-name,
            .site-footer nav a {
                display: inline-flex;
                align-items: center;
                min-height: 44px;
                padding: 4px 8px;
                margin-left: -8px;
            }
            .block-hero-btn,
            .block-cta-btn { min-height: 44px; display: inline-flex; align-items: center; }
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
    {{-- Carries the fallback that re-requests an image in its original format
         when the browser cannot decode the WebP one. --}}
    @include('vela::templates._partials.scripts-footer')
    @stack('scripts')
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
    @php
        $__lead = optional($page->rows->sortBy('order_column')->first())
            ->blocks->sortBy('order_column')->first();
        $__opensWithHero = $__lead && $__lead->type === 'hero';
    @endphp
    {{-- A page that opens with a hero already states its own name, in the
         design's words and at the design's size. Printing the page title
         above it gives the reader two headings for one page. --}}
    @if($page->slug !== 'home' && ! $__opensWithHero)
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
     * The remaining views, written so a theme never has to fall back.
     *
     * The built-in views a missing theme file falls back to are written in
     * utility classes a Tailwind build supplies, and a theme that does not
     * ship one renders them as unstyled text — /posts and /categories came
     * out bare while the homepage looked finished. Writing all six up front
     * means the fallback is never reached.
     */
    public function views(): array
    {
        return [
            'articles' => $this->articles(),
            'article' => $this->article(),
            'categories_index' => $this->categoriesIndex(),
            'categories_show' => $this->categoriesShow(),
        ];
    }

    private function meta(): string
    {
        return <<<'BLADE'
@section('title', $metaTags['title'])
@section('description', $metaTags['description'])
@section('canonical_url', $metaTags['canonical_url'])
@section('og_title', $metaTags['og_title'])
@section('og_description', $metaTags['og_description'])
@section('og_image', $metaTags['og_image'])
BLADE;
    }

    /**
     * One card in a listing. Shared so the three listings cannot drift.
     */
    private function postCard(): string
    {
        return <<<'BLADE'
        <a class="card" href="{{ route('vela.public.posts.show', $post->slug) }}">
            @if($post->main_image)
                {!! vela_image($post->main_image, $post->translated_title, [400, 800]) !!}
            @endif
            <div class="card-body">
                <h3>{{ $post->translated_title }}</h3>
                <p>{{ $post->translated_description }}</p>
                <time>{{ ($post->published_at ?: $post->created_at)?->format('j M Y') }}</time>
            </div>
        </a>
BLADE;
    }

    /**
     * Previous/next by hand: the paginator's own markup expects a CSS
     * framework this theme does not load.
     */
    private function pager(): string
    {
        return <<<'BLADE'
    @if($posts->hasPages())
        <nav class="pager wrap">
            @if($posts->previousPageUrl())
                <a href="{{ $posts->previousPageUrl() }}">&larr; {{ __('vela::public.previous') }}</a>
            @endif
            @if($posts->nextPageUrl())
                <a href="{{ $posts->nextPageUrl() }}">{{ __('vela::public.next') }} &rarr;</a>
            @endif
        </nav>
    @endif
BLADE;
    }

    private function articles(): string
    {
        return "@extends(vela_template_layout())\n\n" . $this->meta() . "\n\n"
            . "@section('content')\n"
            . "<div class=\"page-head wrap\">\n    <h1>{{ __('vela::public.all_articles') }}</h1>\n</div>\n\n"
            . "<div class=\"card-grid wrap\">\n@foreach(\$posts as \$post)\n" . $this->postCard() . "\n@endforeach\n</div>\n\n"
            . $this->pager() . "\n@endsection\n";
    }

    private function categoriesShow(): string
    {
        return "@extends(vela_template_layout())\n\n" . $this->meta() . "\n\n"
            . "@section('content')\n"
            . "<div class=\"page-head wrap\">\n    <h1>{{ \$category->name }}</h1>\n</div>\n\n"
            . "<div class=\"card-grid wrap\">\n@foreach(\$posts as \$post)\n" . $this->postCard() . "\n@endforeach\n</div>\n\n"
            . $this->pager() . "\n@endsection\n";
    }

    private function article(): string
    {
        return <<<'BLADE'
@extends(vela_template_layout())

@section('title', $metaTags['title'])
@section('description', $metaTags['description'])
@section('canonical_url', $metaTags['canonical_url'])
@section('og_title', $metaTags['og_title'])
@section('og_description', $metaTags['og_description'])
@section('og_image', $metaTags['og_image'])

@section('content')
<article class="article wrap">
    <div class="page-head">
        <h1>{{ $post->translated_title }}</h1>
        <time>{{ ($post->published_at ?: $post->created_at)?->format('j M Y') }}</time>
    </div>

    @if($post->main_image)
        {!! vela_image($post->main_image, $post->translated_title, [800, 1200], 'fit', [], 'preload') !!}
    @endif

    <div class="prose">
        @include('vela::templates._partials.article-content', ['post' => $post])
    </div>
</article>

@if($relatedPosts && count($relatedPosts))
<div class="page-head wrap">
    <h2>{{ __('vela::public.latest_articles') }}</h2>
</div>
<div class="card-grid wrap">
    @foreach($relatedPosts as $post)
        <a class="card" href="{{ route('vela.public.posts.show', $post->slug) }}">
            @if($post->main_image)
                {!! vela_image($post->main_image, $post->translated_title, [400, 800]) !!}
            @endif
            <div class="card-body">
                <h3>{{ $post->translated_title }}</h3>
                <p>{{ $post->translated_description }}</p>
            </div>
        </a>
    @endforeach
</div>
@endif
@endsection
BLADE;
    }

    private function categoriesIndex(): string
    {
        return <<<'BLADE'
@extends(vela_template_layout())

@section('title', $metaTags['title'])
@section('description', $metaTags['description'])
@section('canonical_url', $metaTags['canonical_url'])

@section('content')
<div class="page-head wrap">
    <h1>{{ __('vela::public.topics') }}</h1>
</div>

<div class="card-grid wrap">
    @foreach($categories as $category)
        <a class="card" href="{{ route('vela.public.categories.show', Str::slug($category->name)) }}">
            <div class="card-body">
                <h3>{{ $category->name }}</h3>
                @php
                    $count = $category->contents_count ?? $category->contents()->count();
                @endphp
                <p>{{ trans_choice('vela::public.articles_count', $count, ['count' => $count]) }}</p>
            </div>
        </a>
    @endforeach
</div>
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
