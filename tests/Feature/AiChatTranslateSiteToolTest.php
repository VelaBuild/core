<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\AiChat\Tools\TranslateSiteTool;
use VelaBuild\Core\Services\TranslationStatusService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Without a translation tool the chatbot answered "make my site Thai" by
 * rewriting the English content in Thai, destroying the original. These cover
 * the refusals that keep it from reaching the translator with a locale the
 * site would never serve — every one of them is worded to be shown to the user.
 */
class AiChatTranslateSiteToolTest extends PackageTestCase
{
    public function test_it_asks_which_language_rather_than_guessing(): void
    {
        $result = (new TranslateSiteTool())->execute([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('available_locales', $result);
        $this->assertSame(app(TranslationStatusService::class)->sourceLocale(), $result['site_is_written_in']);
    }

    public function test_translating_into_the_language_the_site_is_written_in_is_refused(): void
    {
        $source = app(TranslationStatusService::class)->sourceLocale();

        $result = (new TranslateSiteTool())->execute(['locale' => $source]);

        $this->assertStringContainsString('already written in', $result['error']);
    }

    public function test_a_language_the_site_does_not_serve_is_refused(): void
    {
        // Translating into it would write rows no visitor could ever reach.
        $result = (new TranslateSiteTool())->execute(['locale' => 'xx-nonsense']);

        $this->assertStringContainsString('not set up for', $result['error']);
        $this->assertArrayHasKey('available_locales', $result);
    }

    public function test_it_names_what_it_can_translate_when_given_something_else(): void
    {
        $result = (new TranslateSiteTool())->execute(['locale' => $this->aTargetLocale(), 'surface' => 'menus']);

        $this->assertSame(['pages', 'articles', 'categories'], $result['valid_surfaces']);
    }

    public function test_an_id_without_a_surface_is_refused(): void
    {
        $result = (new TranslateSiteTool())->execute(['locale' => $this->aTargetLocale(), 'id' => 1]);

        $this->assertStringContainsString('surface', $result['error']);
    }

    /** Whichever second language this install offers — the set is config-driven. */
    private function aTargetLocale(): string
    {
        $locale = app(TranslationStatusService::class)->targetLocales()[0] ?? null;
        $this->assertNotNull($locale, 'the site must offer at least one other language');

        return $locale;
    }

    public function test_articles_are_counted_against_the_table_they_actually_live_in(): void
    {
        // The status service probed 'vela_contents' while Content lives in
        // 'vela_articles', so every article read as translated and the whole
        // surface reported nothing to do.
        Content::create([
            'title'      => 'Counted Article',
            'slug'       => 'counted-article',
            'type'       => 'post',
            'status'     => 'published',
            'author_id'  => 1,
            'written_at' => now(),
        ]);

        $status = app(TranslationStatusService::class);
        $locale = $status->targetLocales()[0] ?? null;
        $this->assertNotNull($locale, 'the site must offer at least one other language');

        $this->assertSame(1, $status->coverage()['articles'][$locale]['total']);
        $this->assertContains('Counted Article', array_column($status->missing('articles', $locale), 'label'));
    }
}
