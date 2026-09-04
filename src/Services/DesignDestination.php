<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;

/**
 * Where the design being built is headed.
 *
 * A build has always made one theme, one set of menus and one page, and that
 * page could only ever become the homepage. That is right for the FIRST
 * design a site is given and wrong for every one after it: somebody who has
 * their front page and wants an About page from a second mockup had no way to
 * ask for it, and the theme is site-wide — letting a second build write
 * another one would dress every page in whichever design was uploaded last.
 *
 * So the destination is chosen before the build, and it decides two things:
 * what the build is allowed to touch (a page build writes sections and
 * nothing else — see DesignBuilderService::TOOLS_A_SECTIONS_BUILD_MAY_NOT_USE)
 * and where "keep this" puts the result.
 *
 * The build itself still works on the preview page either way. Writing
 * straight into a live page would put a half-built one in front of visitors,
 * which is the thing the preview page exists to prevent.
 */
class DesignDestination
{
    public const KEY = 'design_destination';

    public const HOMEPAGE = 'homepage';
    public const PAGE = 'page';

    /**
     * @return array{mode: string, slug: string, title: string, existing: bool}
     */
    public function read(): array
    {
        $stored = json_decode((string) VelaConfig::where('key', self::KEY)->value('value'), true);

        if (!is_array($stored) || ($stored['mode'] ?? '') !== self::PAGE) {
            return $this->homepage();
        }

        $slug = trim((string) ($stored['slug'] ?? ''));

        // A destination page deleted between the build and the decision is
        // not a reason to lose the build: it becomes a new page under the
        // slug it was going to replace.
        if ($slug === '' || $slug === 'home') {
            return $this->homepage();
        }

        return [
            'mode' => self::PAGE,
            'slug' => $slug,
            'title' => trim((string) ($stored['title'] ?? '')) ?: $slug,
            'existing' => Page::where('slug', $slug)->exists(),
        ];
    }

    /**
     * @return array{mode: string, slug: string, title: string, existing: bool}
     */
    private function homepage(): array
    {
        return [
            'mode' => self::HOMEPAGE,
            'slug' => 'home',
            'title' => '',
            'existing' => Page::where('slug', 'home')->exists(),
        ];
    }

    public function setHomepage(): void
    {
        VelaConfig::updateOrCreate(['key' => self::KEY], ['value' => json_encode(['mode' => self::HOMEPAGE])]);
    }

    public function setPage(string $slug, string $title): void
    {
        VelaConfig::updateOrCreate(['key' => self::KEY], ['value' => json_encode([
            'mode' => self::PAGE,
            'slug' => $slug,
            'title' => $title,
        ])]);
    }

    /**
     * Whether this build writes sections only, leaving the frame alone.
     */
    public function isSectionsOnly(): bool
    {
        return $this->read()['mode'] === self::PAGE;
    }

    /**
     * What the button that keeps the design should say it will do.
     */
    public function keepLabel(): string
    {
        $destination = $this->read();

        if ($destination['mode'] === self::HOMEPAGE) {
            return 'Use this as my homepage';
        }

        return $destination['existing']
            ? 'Use this as "' . $destination['title'] . '"'
            : 'Add this as "' . $destination['title'] . '"';
    }
}
