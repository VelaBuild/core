<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrowserRenderingService
{
    public function isConfigured(): bool
    {
        return !empty(config('vela.browser_rendering.url'));
    }

    public function screenshot(string $url, array $options = []): ?string
    {
        $endpoint = rtrim(config('vela.browser_rendering.url'), '/') . '/screenshot';

        $payload = array_merge([
            'url' => $url,
            'viewport' => ['width' => $options['width'] ?? 1280, 'height' => $options['height'] ?? 800],
            'format' => $options['format'] ?? 'png',
            'fullPage' => $options['full_page'] ?? false,
        ], $options['extra'] ?? []);

        try {
            $response = Http::timeout($options['timeout'] ?? 30)
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                Log::error('Browser rendering screenshot failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return base64_encode($response->body());
        } catch (\Throwable $e) {
            Log::error('Browser rendering screenshot error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function pdf(string $url, array $options = []): ?string
    {
        $endpoint = rtrim(config('vela.browser_rendering.url'), '/') . '/pdf';

        $payload = array_merge([
            'url' => $url,
            'format' => 'A4',
            'printBackground' => true,
        ], $options['extra'] ?? []);

        try {
            $response = Http::timeout($options['timeout'] ?? 30)
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                Log::error('Browser rendering PDF failed', ['url' => $url, 'status' => $response->status()]);
                return null;
            }

            return base64_encode($response->body());
        } catch (\Throwable $e) {
            Log::error('Browser rendering PDF error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function html(string $url, array $options = []): ?string
    {
        $endpoint = rtrim(config('vela.browser_rendering.url'), '/') . '/content';

        try {
            $response = Http::timeout($options['timeout'] ?? 30)
                ->post($endpoint, ['url' => $url]);

            if (!$response->successful()) {
                Log::error('Browser rendering content failed', ['url' => $url, 'status' => $response->status()]);
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::error('Browser rendering content error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function evaluate(string $url, string $script, array $options = []): ?array
    {
        $endpoint = rtrim(config('vela.browser_rendering.url'), '/') . '/evaluate';

        try {
            $response = Http::timeout($options['timeout'] ?? 30)
                ->post($endpoint, [
                    'url' => $url,
                    'script' => $script,
                    'viewport' => ['width' => $options['width'] ?? 1280, 'height' => $options['height'] ?? 800],
                    'waitUntil' => $options['wait_until'] ?? 'networkidle0',
                ]);

            if (!$response->successful()) {
                Log::error('Browser rendering evaluate failed', ['url' => $url, 'status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('Browser rendering evaluate error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function extractStructured(string $url, array $options = []): ?array
    {
        $script = <<<'JS'
(() => {
    const result = {};
    result.title = document.title;
    result.meta = {};
    document.querySelectorAll('meta[name],meta[property]').forEach(m => {
        result.meta[m.getAttribute('name') || m.getAttribute('property')] = m.getAttribute('content');
    });
    result.headings = [];
    document.querySelectorAll('h1,h2,h3').forEach(h => {
        result.headings.push({ tag: h.tagName, text: h.textContent.trim() });
    });
    result.links = [];
    document.querySelectorAll('a[href]').forEach(a => {
        if (a.href && !a.href.startsWith('javascript:')) result.links.push({ text: a.textContent.trim().substring(0, 100), href: a.href });
    });
    result.images = [];
    document.querySelectorAll('img[src]').forEach(img => {
        result.images.push({ src: img.src, alt: img.alt, width: img.naturalWidth, height: img.naturalHeight });
    });
    result.colors = [];
    const seen = new Set();
    document.querySelectorAll('*').forEach(el => {
        const s = getComputedStyle(el);
        [s.color, s.backgroundColor].forEach(c => { if (c && c !== 'rgba(0, 0, 0, 0)' && !seen.has(c)) { seen.add(c); result.colors.push(c); } });
    });
    result.colors = result.colors.slice(0, 20);
    result.fonts = [];
    const fontsSeen = new Set();
    document.querySelectorAll('*').forEach(el => {
        const f = getComputedStyle(el).fontFamily.split(',')[0].trim().replace(/['"]/g, '');
        if (f && !fontsSeen.has(f)) { fontsSeen.add(f); result.fonts.push(f); }
    });
    result.fonts = result.fonts.slice(0, 10);
    result.viewport = { width: window.innerWidth, height: document.documentElement.scrollHeight };
    return result;
})()
JS;

        return $this->evaluate($url, $script, $options);
    }

    /**
     * Walk the rendered page and describe each top-level section.
     *
     * The structured extract above flattens the whole document into one list
     * of headings and one list of images, which says nothing about what sits
     * inside which section — so a rebuild from it loses the arrangement. This
     * keeps the sections separate and reports the computed styling of each,
     * which is the part no amount of reading raw HTML recovers.
     */
    public function extractSections(string $url, array $options = []): ?array
    {
        $script = <<<'JS'
(() => {
    const txt = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : '');
    const cut = (s, n) => (s.length > n ? s.slice(0, n) + '…' : s);

    // Descend through single-child wrappers, then expand <main> in place, so
    // the result is the sections a reader sees rather than one layout div.
    let container = document.body;
    for (let i = 0; i < 5; i++) {
        const kids = [...container.children].filter(el => !['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName));
        if (kids.length !== 1 || ['HEADER', 'FOOTER', 'MAIN', 'SECTION', 'NAV'].includes(kids[0].tagName)) break;
        container = kids[0];
    }
    const top = [];
    for (const el of container.children) {
        if (['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName)) continue;
        if (el.tagName === 'MAIN') { top.push(...el.children); continue; }
        top.push(el);
    }

    const repeated = (root) => {
        let best = null;
        const walk = (el, depth) => {
            if (depth > 6) return;
            const kids = [...el.children];
            if (kids.length >= 2) {
                const sig = (k) => k.tagName + '|' + [...k.classList].slice(0, 4).sort().join(' ');
                const first = sig(kids[0]);
                if (kids.every(k => sig(k) === first)) {
                    const titles = kids.map(k => cut(txt(k.querySelector('h2,h3,h4,h5,strong')) || txt(k), 120)).filter(Boolean);
                    if (titles.length >= 2 && (!best || kids.length > best.count)) {
                        best = { count: kids.length, titles: titles.slice(0, 12) };
                    }
                }
            }
            for (const k of el.children) walk(k, depth + 1);
        };
        walk(root, 0);
        return best;
    };

    const sections = [];
    top.forEach((el, i) => {
        const text = txt(el);
        const imgs = [...el.querySelectorAll('img')]
            .map(img => ({ src: img.currentSrc || img.src, alt: img.alt || null }))
            .filter(im => im.src && !im.src.startsWith('data:'));
        const buttons = [...el.querySelectorAll('a,button')]
            .filter(b => {
                const t = txt(b);
                if (!t || t.length > 60) return false;
                const c = (b.className || '').toString().toLowerCase();
                return b.tagName === 'BUTTON' || c.includes('btn') || c.includes('button') || c.includes('cta');
            })
            .slice(0, 8)
            .map(b => ({ text: txt(b), href: b.getAttribute('href') || null }));
        if (text.length < 12 && !imgs.length && !buttons.length && !el.querySelector('form')) return;

        const s = getComputedStyle(el);
        const rect = el.getBoundingClientRect();
        const headings = [...el.querySelectorAll('h1,h2,h3,h4')]
            .map(h => ({ level: +h.tagName[1], text: cut(txt(h), 200) }))
            .filter(h => h.text)
            .slice(0, 12);
        const lead = [...el.querySelectorAll('p')].map(txt).find(t => t.length >= 30) || null;
        const grid = s.display.includes('grid') ? s.gridTemplateColumns : null;

        sections.push({
            index: sections.length + 1,
            tag: el.tagName.toLowerCase(),
            id: el.id || null,
            class: cut((el.className || '').toString(), 160) || null,
            heading: headings[0] ? headings[0].text : null,
            heading_level: headings[0] ? headings[0].level : null,
            subheadings: headings.slice(1).map(h => h.text),
            lead_text: lead ? cut(lead, 400) : null,
            buttons: buttons,
            images: imgs.slice(0, 12),
            image_count: imgs.length,
            has_form: !!el.querySelector('form'),
            repeated_items: repeated(el),
            text_chars: text.length,
            text: cut(text, 1200),
            style: {
                background: s.backgroundColor,
                background_image: s.backgroundImage === 'none' ? null : cut(s.backgroundImage, 200),
                color: s.color,
                padding: s.padding,
                text_align: s.textAlign,
                display: s.display,
                grid_columns: grid,
                height: Math.round(rect.height),
            },
        });
    });

    return { section_count: sections.length, sections: sections };
})()
JS;

        return $this->evaluate($url, $script, $options);
    }

    /**
     * Hand back every stylesheet the browser has already loaded.
     *
     * A plain HTTP client is often refused the CSS files a page links to —
     * CDNs and bot checks answer it with a 403 — and then a copied section
     * arrives with its markup and none of its design. The browser has them
     * parsed already; cross-origin sheets it cannot read are re-fetched from
     * inside the page, where the request looks like every other one it makes.
     */
    public function collectStylesheets(string $url, array $options = []): ?string
    {
        $script = <<<'JS'
(async () => {
    const parts = [];
    const remote = [];
    for (const sheet of document.styleSheets) {
        try {
            const rules = sheet.cssRules;
            let text = '';
            for (const rule of rules) text += rule.cssText + '\n';
            if (text) parts.push(text);
        } catch (e) {
            // Cross-origin: the rules are unreadable, but the file is not.
            if (sheet.href) remote.push(sheet.href);
        }
    }
    for (const href of remote.slice(0, 8)) {
        try {
            const response = await fetch(href);
            if (response.ok) parts.push(await response.text());
        } catch (e) { /* nothing more to try for this one */ }
    }
    return parts.join('\n').slice(0, 2000000);
})()
JS;

        $result = $this->evaluate($url, $script, $options);
        if (!is_array($result)) {
            return null;
        }

        foreach ([$result['result'] ?? null, $result['css'] ?? null, $result[0] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Read the page's real design tokens off computed styles.
     *
     * Scraping hex codes out of a stylesheet gives thirty colours in no
     * particular order and nothing about type scale or spacing, so a rebuilt
     * page lands the palette and still feels wrong. These are the values an
     * actual visitor's browser resolved.
     */
    public function extractDesignTokens(string $url, array $options = []): ?array
    {
        $script = <<<'JS'
(() => {
    const pick = (el) => {
        if (!el) return null;
        const s = getComputedStyle(el);
        return {
            font_family: s.fontFamily,
            font_size: s.fontSize,
            font_weight: s.fontWeight,
            line_height: s.lineHeight,
            letter_spacing: s.letterSpacing,
            color: s.color,
            background: s.backgroundColor,
            text_transform: s.textTransform,
            border_radius: s.borderRadius,
            padding: s.padding,
            box_shadow: s.boxShadow === 'none' ? null : s.boxShadow,
        };
    };

    const elements = {
        body: document.body,
        h1: document.querySelector('h1'),
        h2: document.querySelector('h2'),
        h3: document.querySelector('h3'),
        paragraph: document.querySelector('p'),
        link: document.querySelector('a'),
        button: document.querySelector('button, .btn, [class*="button"]'),
        card: document.querySelector('[class*="card"], article'),
        input: document.querySelector('input, textarea'),
    };
    const tokens = {};
    for (const [name, el] of Object.entries(elements)) {
        const v = pick(el);
        if (v) tokens[name] = v;
    }

    // Custom properties declared on :root — the site's own palette names,
    // which map straight onto Vela's CSS variables.
    const custom = {};
    for (const sheet of document.styleSheets) {
        let rules;
        try { rules = sheet.cssRules; } catch (e) { continue; }
        for (const rule of rules || []) {
            if (!rule.style || !/^(:root|html)$/.test(rule.selectorText || '')) continue;
            for (const prop of rule.style) {
                if (prop.startsWith('--')) custom[prop] = rule.style.getPropertyValue(prop).trim();
            }
        }
    }

    // Rank colours by how much of the page actually uses them, so the primary
    // brand colour comes first instead of whichever hex appeared earliest.
    const usage = new Map();
    document.querySelectorAll('*').forEach(el => {
        const s = getComputedStyle(el);
        [s.color, s.backgroundColor, s.borderTopColor].forEach(c => {
            if (!c || c === 'rgba(0, 0, 0, 0)' || c === 'transparent') return;
            usage.set(c, (usage.get(c) || 0) + 1);
        });
    });
    const colors = [...usage.entries()].sort((a, b) => b[1] - a[1]).slice(0, 16)
        .map(([color, count]) => ({ color, uses: count }));

    const fonts = [...new Set([...document.querySelectorAll('*')]
        .map(el => getComputedStyle(el).fontFamily).filter(Boolean))].slice(0, 8);

    const fontLinks = [...document.querySelectorAll('link[rel="stylesheet"]')]
        .map(l => l.href).filter(h => /fonts\.|font/i.test(h)).slice(0, 8);

    return {
        tokens: tokens,
        custom_properties: Object.keys(custom).length ? custom : null,
        colors_by_usage: colors,
        font_families: fonts,
        font_stylesheets: fontLinks,
        page_background: getComputedStyle(document.body).backgroundColor,
        max_content_width: (() => {
            const el = document.querySelector('[class*="container"], main, .wrapper');
            return el ? Math.round(el.getBoundingClientRect().width) : null;
        })(),
    };
})()
JS;

        return $this->evaluate($url, $script, $options);
    }
}
