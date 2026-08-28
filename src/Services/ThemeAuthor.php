<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Writes a theme of its own for a site, rather than dressing up someone else's.
 *
 * The design builder used to pick the closest of the shipped themes and
 * recolour it, so a design's fidelity was capped by what that theme's blocks
 * could express — a stats strip, a price on a card, a two-column hero simply
 * had nowhere to go. A theme written for the design has no such ceiling.
 *
 * Themes are written under the app's own resources/views/templates, which
 * VelaServiceProvider already auto-discovers, so nothing in the package is
 * touched and a composer update cannot overwrite the work.
 */
class ThemeAuthor
{
    /**
     * Views a theme may define. Anything it leaves out falls back to a plain
     * core view — see vela_template_view() — so a theme is useful long before
     * it is complete, and an unfinished one does not take the site down.
     */
    public const VIEWS = [
        'layout' => 'The frame every page sits in: <head>, header, navigation, footer, and @yield(\'content\').',
        'page' => 'A page built in the block editor, including the homepage.',
        'articles' => 'The list of all articles.',
        'article' => 'One article.',
        'categories_index' => 'The list of categories.',
        'categories_show' => 'One category and its articles.',
    ];

    public function directory(string $theme): string
    {
        return resource_path('views/templates/' . $theme);
    }

    public function exists(string $theme): bool
    {
        return is_dir($this->directory($theme));
    }

    /**
     * The folder name a theme gets, from whatever it was called.
     *
     * Names arrive as the model wrote them, and "ZercurityTheme" slugs to
     * "zercuritytheme" because slugging does not split words that were only
     * ever separated by capitals. Saying "theme" in a theme's name adds
     * nothing either, so it goes.
     */
    private function themeSlug(string $name): string
    {
        $slug = Str::slug(Str::snake(trim($name), '-'));
        $trimmed = preg_replace('/-(theme|template)$/', '', $slug);

        // Only if something is left: a theme somebody really did call "theme"
        // keeps the name and is turned away below instead.
        return $trimmed !== '' ? $trimmed : $slug;
    }

    /**
     * Why this name cannot be a theme, if it cannot.
     *
     * Builds name their own themes, and left to themselves they produce names
     * that describe nothing: one run called its theme "active", having read
     * the word off the site's settings. Named that way a theme is impossible
     * to tell from the next one, which matters now that a site collects one
     * per build.
     */
    private function rejectUnusableName(string $theme): ?string
    {
        $meaningless = [
            'active', 'current', 'new', 'custom', 'default', 'theme', 'template',
            'site', 'website', 'home', 'homepage', 'main', 'design', 'my-site', 'untitled',
        ];

        if (in_array($theme, $meaningless, true)) {
            return 'A theme called "' . $theme . '" says nothing about which one it is, and a site collects one of '
                . 'these per build. Name it after the site the design is for — the wordmark in the header, the name '
                . 'in the footer.';
        }

        $shipped = glob(__DIR__ . '/../../resources/views/templates/*', GLOB_ONLYDIR) ?: [];

        foreach ($shipped as $path) {
            if (basename($path) === $theme) {
                return 'Vela already ships a theme called "' . $theme . '", and one written here would hide it. '
                    . 'Choose the name of the site the design is for instead.';
            }
        }

        return null;
    }

    /**
     * Create an empty theme and its manifest. No views: those are written one
     * at a time, so a failure part-way leaves the rest falling back rather
     * than half a design.
     */
    public function scaffold(string $name, string $label, string $description = ''): string
    {
        $theme = $this->themeSlug($name);

        if ($theme === '') {
            throw new \RuntimeException('A theme needs a name that survives being turned into a folder name.');
        }

        if ($error = $this->rejectUnusableName($theme)) {
            throw new \RuntimeException($error);
        }

        $directory = $this->directory($theme);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the theme folder at ' . $directory);
        }

        $manifest = [
            'label' => $label ?: Str::headline($theme),
            'namespace' => 'vela-' . $theme,
            'description' => $description,
            'category' => 'custom',
            'options' => new \stdClass(),
        ];

        file_put_contents(
            $directory . '/template.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        // Themes are discovered when the app boots, and this one did not exist
        // then. Without registering it here, the theme is written and then
        // refused by switch_template as unknown until the next process starts
        // — so a build would author a theme and quietly leave the old one on.
        app(\VelaBuild\Core\Vela::class)->templates()->register($theme, [
            'path' => $directory,
            'label' => $manifest['label'],
            'namespace' => $manifest['namespace'],
            'description' => $description,
        ]);

        // A theme starts complete rather than empty: frame, navigation,
        // footer and a rule for every block, all driven by the tokens at the
        // top. Written from nothing instead, a build produced a body holding
        // only @yield('content') and forty-five lines of crude CSS.
        $skeleton = app(ThemeSkeleton::class);
        file_put_contents($directory . '/layout.blade.php', $skeleton->layout());
        file_put_contents($directory . '/page.blade.php', $skeleton->page());

        // All six, not just the two: a view left out falls back to a built-in
        // written in utility classes that only a Tailwind build supplies, so
        // /posts and /categories came out as unstyled text under a finished
        // homepage.
        foreach ($skeleton->views() as $view => $contents) {
            file_put_contents($directory . '/' . $view . '.blade.php', $contents);
        }

        return $theme;
    }

    /**
     * Remove a theme this site owns.
     *
     * Builds make a theme each time, and nothing could ever remove one: a
     * handful of runs leaves a list of near-duplicates with names read off the
     * design badly — "zercurity", "zecuritytheme", one simply called "active"
     * — sitting in the theme picker beside the real ones, with no way out of
     * the admin at all.
     *
     * Two things it will not do. A theme that came with Vela is not ours to
     * delete: it lives in the package, and composer would put it back. Nor
     * the theme in use — the site would lose its layout mid-request — so
     * switching away comes first, which is also the moment someone sees what
     * they are about to lose.
     *
     * What is removed is kept for now, under the same name, so a wrong click
     * is recoverable by hand.
     */
    public function delete(string $theme): void
    {
        if (!$this->exists($theme)) {
            throw new \RuntimeException(
                'There is no theme called "' . $theme . '" belonging to this site. The themes that come with Vela '
                . 'live in the package and cannot be deleted — they would return with the next update.'
            );
        }

        if ($theme === config('vela.template.active')) {
            throw new \RuntimeException(
                'This is the theme the site is using. Switch to another one first, then delete it.'
            );
        }

        $trash = storage_path('app/vela-theme-deleted/' . $theme);

        File::deleteDirectory($trash);
        File::ensureDirectoryExists(dirname($trash));
        File::copyDirectory($this->directory($theme), $trash);
        File::deleteDirectory($this->directory($theme));
    }

    /**
     * The token values a theme is carrying now.
     *
     * They live in the layout's :root block, which is also where setTokens
     * rewrites them, so reading them back is a matter of looking there.
     */
    public function currentTokens(string $theme): array
    {
        $file = $this->directory($theme) . '/layout.blade.php';

        if (!is_file($file)) {
            return [];
        }

        preg_match_all(
            '/--([a-z0-9-]+):\s*([^;]+);/i',
            (string) file_get_contents($file),
            $matches,
            PREG_SET_ORDER
        );

        $tokens = [];

        foreach ($matches as $match) {
            $tokens[$match[1]] = trim($match[2]);
        }

        return $tokens;
    }

    /**
     * Change the design decisions at the top of a theme's stylesheet.
     *
     * The whole of the skeleton reads from these, so setting a dozen of them
     * restyles the site — and it is a job of picking values off a design
     * rather than composing several hundred lines of CSS, which is what the
     * model is actually good at.
     */
    public function setTokens(string $theme, array $tokens): array
    {
        $file = $this->directory($theme) . '/layout.blade.php';

        if (!is_file($file)) {
            throw new \RuntimeException('The theme "' . $theme . '" has no layout to set tokens on.');
        }

        $contents = (string) file_get_contents($file);
        $applied = [];
        $unknown = [];

        foreach ($tokens as $name => $value) {
            $name = ltrim((string) $name, '-');

            if (!array_key_exists($name, ThemeSkeleton::TOKENS)) {
                $unknown[] = $name;
                continue;
            }

            $pattern = '/(--' . preg_quote($name, '/') . ':)[^;]*;/';
            $replaced = preg_replace($pattern, '$1 ' . str_replace('$', '\\$', (string) $value) . ';', $contents, 1, $count);

            if ($count) {
                $contents = $replaced;
                $applied[$name] = $value;
            }
        }

        file_put_contents($file, $contents);

        return ['applied' => $applied, 'unknown' => $unknown];
    }

    /**
     * Write one view, refusing anything that would not compile.
     *
     * A Blade file with a syntax error takes down every page that renders it,
     * and the model writing it has no way to find that out. Compiling here
     * turns a broken site into a message it can act on.
     */
    public function writeView(string $theme, string $view, string $contents): void
    {
        if (!array_key_exists($view, self::VIEWS)) {
            throw new \RuntimeException(
                'There is no "' . $view . '" view in a theme. Choose one of: ' . implode(', ', array_keys(self::VIEWS)) . '.'
            );
        }

        if (!$this->exists($theme)) {
            throw new \RuntimeException('The theme "' . $theme . '" does not exist yet — create it first.');
        }

        $contents = $this->unescapeJsonArtefacts($contents);

        // What is being replaced, so a rewrite can be held to what the file
        // already did rather than judged on its own.
        $path = $this->directory($theme) . '/' . $view . '.blade.php';
        $existing = is_file($path) ? (string) file_get_contents($path) : '';

        $this->assertCompiles($contents);
        $this->assertUsable($view, $contents, $existing);

        file_put_contents($path, $contents);
    }

    /**
     * Undo JSON's escaping where it has survived into the markup.
     *
     * A view arrives as a string inside a tool call, and one written with the
     * slashes and quotes escaped a second time reaches us as `<\/div>` and
     * `href=\"…\"`. Nothing then closes a single tag: the page has no `</body>`
     * at all, and every attribute carries a stray backslash. Neither sequence
     * means anything in HTML or Blade, so removing them cannot damage a file
     * that was written correctly in the first place.
     */
    private function unescapeJsonArtefacts(string $contents): string
    {
        if (!str_contains($contents, '<\\/') && !str_contains($contents, '=\\"')) {
            return $contents;
        }

        return str_replace(['\\/', '\\"'], ['/', '"'], $contents);
    }

    /**
     * Hold a view written by any route to the same checks.
     *
     * writeView is not the only way into a theme: edit_template_file writes
     * into whichever template is active, which is this one whenever a build
     * has switched to what it wrote. A guard on one door is a guard on none —
     * refuse write_theme_file and the model reaches for the other tool, which
     * regenerates the whole file from a 4000-token budget the skeleton layout
     * does not fit inside.
     *
     * Throws with something the caller can act on; returns quietly for a file
     * that is not one of a theme's views.
     */
    public function guardView(string $path, string $contents, string $existing = ''): void
    {
        $view = basename($path, '.blade.php');

        if (!array_key_exists($view, self::VIEWS)) {
            return;
        }

        $this->assertUsable($view, $contents, $existing);
    }

    /**
     * Catch the mistakes that compile but cannot run.
     *
     * A layout written as a Blade component — `{{ $slot }}` where the frame's
     * content goes — is perfectly valid Blade and fails on every page with
     * "Undefined variable $slot", because Vela's views reach their layout
     * through @extends. Compiling cannot see it; only rendering can, and by
     * then the site is down.
     */
    private function assertUsable(string $view, string $contents, string $existing = ''): void
    {
        if ($view !== 'layout') {
            if (!str_contains($contents, '@extends')) {
                throw new \RuntimeException(
                    'A "' . $view . '" view must begin with @extends(vela_template_layout()) and put its markup in @section(\'content\').'
                );
            }

            return;
        }

        if (preg_match('/\{\{\s*\$slot\s*\}\}/', $contents) || str_contains($contents, '{!! $slot !!}')) {
            throw new \RuntimeException(
                'A layout is not a Blade component: $slot does not exist here and every page would fail with "Undefined variable $slot". Put @yield(\'content\') where the page goes.'
            );
        }

        if (!str_contains($contents, "@yield('content')") && !str_contains($contents, '@yield("content")')) {
            throw new \RuntimeException(
                'A layout must contain @yield(\'content\') — without it every page renders as an empty frame.'
            );
        }

        $this->assertStylesRealBlocks($contents, $existing);
    }

    /**
     * Refuse a stylesheet that styles class names nothing renders, and refuse
     * a rewrite that styles fewer of them than the file it replaces.
     *
     * CSS that matches no element is the quietest failure there is: the page
     * loads, the rules are ignored, and the design simply does not appear. A
     * layout written without reading list_block_types invents plausible names
     * — .block-accent, .block-text-primary — and every rule misses.
     *
     * A rewrite fails the same way for the opposite reason. Asked to change a
     * colour, the model writes the whole layout again from memory and the
     * dozen rules it happens to recall replace the skeleton's complete set.
     * Every check still passes — the file compiles, it yields content, it
     * names real classes — and the site comes back up with most of itself
     * unstyled. Holding a rewrite to what the file already covered is what
     * catches that, because nothing downstream can: the page still answers.
     */
    private function assertStylesRealBlocks(string $contents, string $existing = ''): void
    {
        if (!str_contains($contents, '<style')) {
            return;
        }

        $inUse = $this->classesInUse($this->blockTypesOnPages());

        if (!$inUse) {
            return;
        }

        // Whole selector only: ".block-text" lives inside the invented
        // ".block-text-primary", and a substring match would accept a
        // stylesheet made entirely of names nothing renders.
        $styled = fn (string $css) => array_values(array_filter(
            $inUse,
            fn ($class) => (bool) preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $css)
        ));

        $covered = $styled($contents);

        if (!$covered) {
            throw new \RuntimeException(
                'This stylesheet does not mention a single class the site\'s blocks actually render with, so none of it would apply. '
                . 'The blocks on this site use: ' . implode(', ', array_slice($inUse, 0, 40)) . '. '
                . 'Call list_block_types for the full set and write the rules against those names.'
            );
        }

        if ($existing === '' || !str_contains($existing, '<style')) {
            return;
        }

        $lost = array_values(array_diff($styled($existing), $covered));

        if (!$lost) {
            return;
        }

        throw new \RuntimeException(
            'This would drop the styling for ' . count($lost) . ' class(es) the site is using right now: '
            . implode(', ', array_slice($lost, 0, 40)) . '. '
            . 'The page would still load, so nothing would report it — those sections would simply come out unstyled. '
            . 'Keep those rules and change what you meant to change. '
            . 'To change colours, type or spacing, call set_theme_tokens instead: it rewrites the theme\'s tokens and leaves every rule in place.'
        );
    }

    /**
     * Block types in use anywhere on the site.
     */
    private function blockTypesOnPages(): array
    {
        try {
            return \VelaBuild\Core\Models\PageBlock::query()->distinct()->pluck('type')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The views a theme has so far.
     */
    public function writtenViews(string $theme): array
    {
        if (!$this->exists($theme)) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::VIEWS),
            fn ($view) => is_file($this->directory($theme) . '/' . $view . '.blade.php')
        ));
    }

    /**
     * What a theme's views are handed and what they must do.
     *
     * A theme written without this is a theme that renders an empty article
     * list and a homepage with no content in it — the block editor's output,
     * the pagination and the meta tags all arrive through named things a
     * model cannot guess.
     */
    public function contract(): string
    {
        return <<<'CONTRACT'
A Vela theme is a folder of Blade views. Each is optional: anything you do not
write falls back to a plain built-in view, so the site keeps working while the
theme is still being written.

EVERY VIEW EXCEPT layout STARTS:
    @extends(vela_template_layout())
    @section('content')
        ...
    @endsection

layout.blade.php — the frame
    Must contain @yield('content'). Put the <!doctype html>, <head>, the site
    header and navigation, and the footer here.
    In <head>, include the meta partials rather than writing tags by hand:
        @include('vela::templates._partials.meta-seo')
        @include('vela::templates._partials.meta-opengraph')
    Site name:        {{ config('app.name') }}
    Sitewide CSS:     put your stylesheet in a <style> block in <head>.
    Navigation links: route('vela.public.home'), route('vela.public.posts.index'),
                      route('vela.public.categories.index')

page.blade.php — a page built in the block editor, the homepage included
    Given: $page
    Render its content with — and only with — this include, which draws the
    rows and blocks the customer edits in the admin:
        @include('vela::templates._partials.page-rows', ['page' => $page])
    Wrap that include in an element carrying these classes so page-level CSS
    can find it:
        <div class="page-content page-slug-{{ $page->slug }} page-id-{{ $page->id }}">
    $page->slug === 'home' is the homepage; other pages usually want a title
    heading above the content, the homepage does not.
    Also honour: $page->custom_css and $page->custom_js, in <style>/<script>.
    Meta: @section('title', $page->meta_title ?: $page->title), and only
    define @section('description', ...) when $page->meta_description is set.

articles.blade.php — every article
    Given: $posts (a paginator), $categories, $metaTags
    Loop $posts; each has translated_title, translated_description, slug,
    main_image, published_at, created_at, categories.
    Link with route('vela.public.posts.show', $post->slug).
    Emit {{ $posts->links() }} or the rest of the archive is unreachable.

article.blade.php — one article
    Given: $post, $relatedPosts, $categories, $metaTags
    $post->translated_title, translated_description, main_image, published_at,
    categories. The body is NOT html: articles are stored as editor blocks and
    printing translated_content puts raw JSON on the page. Render it with
        @include('vela::templates._partials.article-content', ['post' => $post])

categories_index.blade.php — every category
    Given: $categories, $metaTags
    Link with route('vela.public.categories.show', Str::slug($category->name)).

categories_show.blade.php — one category
    Given: $category, $posts (a paginator), $categories, $metaTags
    Same as articles.blade.php, for this category.

META TAGS
    In every view except page, pass the prepared values straight through:
        @section('title', $metaTags['title'])
        @section('description', $metaTags['description'])
        @section('canonical_url', $metaTags['canonical_url'])
        @section('og_title', $metaTags['og_title'])
        @section('og_image', $metaTags['og_image'])

IMAGES
    {!! vela_image($post->main_image, $post->translated_title, [400, 800, 1200]) !!}
    gives a responsive, lazy-loaded picture. For a hero, pass 'preload' as the
    sixth argument.

RULES
    Write plain HTML and your own CSS. Do not link to a CSS framework or any
    other external file — nothing outside the site is fetched at render time.
    Class names are yours to choose; prefix them so they cannot collide with
    the admin's own (mn-, ed-, md- are taken by shipped themes).
    The site must work on a phone: write the layout responsively.
CONTRACT;
    }

    /**
     * The class names a block type renders with.
     *
     * Read from the block's own view rather than kept in a list, so they
     * cannot drift from what it really emits. A Blade expression inside a
     * class attribute is trimmed back to its literal prefix, which is the
     * part a stylesheet can depend on.
     */
    public function blockClasses(string $type): array
    {
        $view = __DIR__ . '/../../resources/views/public/pages/blocks/' . $type . '.blade.php';

        if (!is_file($view)) {
            return [];
        }

        preg_match_all('/class="([^"]*)"/', (string) file_get_contents($view), $matches);

        $classes = [];

        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/\s+/', trim(explode('{{', $attribute)[0])) ?: [] as $class) {
                if ($class !== '' && !in_array($class, $classes, true)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * Every class the blocks currently on a page will render with.
     */
    public function classesInUse(array $types): array
    {
        $classes = [];

        foreach (array_unique($types) as $type) {
            $classes = array_merge($classes, $this->blockClasses($type));
        }

        return array_values(array_unique($classes));
    }

    /**
     * Turn the Blade into PHP and check the result parses.
     *
     * Blade's own compiler reports almost nothing: it happily produces broken
     * PHP from an unclosed directive, and the failure only appears when a
     * visitor loads the page.
     */
    private function assertCompiles(string $contents): void
    {
        try {
            $php = Blade::compileString($contents);
        } catch (\Throwable $e) {
            throw new \RuntimeException('That Blade could not be compiled: ' . $e->getMessage());
        }

        $check = tempnam(sys_get_temp_dir(), 'vela-blade-') . '.php';
        file_put_contents($check, $php);

        try {
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($check)), $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    'That Blade compiles to PHP that will not parse — usually an unclosed @if, @foreach or @section. '
                    . trim(str_replace($check, 'the view', implode(' ', $output)))
                );
            }
        } finally {
            @unlink($check);
        }
    }
}
