<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use VelaBuild\Core\Services\AiChat\Tools\CreateThemeTool;
use VelaBuild\Core\Services\DesignPreviewFrame;
use VelaBuild\Core\Services\ThemeAuthor;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * What a theme ends up being called, and whose theme a build may write over.
 *
 * Both come back to the same fact: the name is generated from the design, so
 * it is the same every rebuild. That is what makes a rebuild reuse its theme
 * rather than pile up near-duplicates, and it is what made a build land on top
 * of a theme that was not its own.
 */
class ThemeNamingTest extends PackageTestCase
{
    use RefreshDatabase;

    private function cleanUp(string $theme): void
    {
        File::deleteDirectory(resource_path('views/templates/' . $theme));
        File::deleteDirectory(storage_path('app/vela-theme-replaced/' . $theme));
    }

    public function test_a_name_becomes_the_folder_a_person_would_expect(): void
    {
        $author = app(ThemeAuthor::class);

        $cases = [
            // Slugging alone does not split words separated only by capitals.
            'ZercurityTheme' => 'zercurity',
            'Zercurity Template' => 'zercurity',
            // And splitting on every capital broke the ones that belong
            // together: "Vela CMS" came out as "vela-c-m-s", which is a real
            // theme sitting on a real site.
            'Vela CMS' => 'vela-cms',
            'VelaCMS' => 'vela-cms',
            'IBM Watson Docs' => 'ibm-watson-docs',
            // An acronym running straight into the next word: the break goes
            // before the last capital, which is where that word starts.
            'CMSPortal' => 'cms-portal',
            'ZZ Top Records' => 'zz-top-records',
            'myAppTheme' => 'my-app',
            'Literature Site' => 'literature-site',
        ];

        foreach ($cases as $name => $expected) {
            try {
                $this->assertSame($expected, $author->scaffold($name, $name), $name);
            } finally {
                $this->cleanUp($expected);
            }
        }
    }

    public function test_a_name_with_no_latin_letters_is_refused_rather_than_left_unopenable(): void
    {
        $this->expectException(\RuntimeException::class);

        app(ThemeAuthor::class)->scaffold('ร้านกาแฟ', 'ร้านกาแฟ');
    }

    public function test_a_build_takes_the_next_free_name_rather_than_a_theme_that_is_not_its_own(): void
    {
        $author = app(ThemeAuthor::class);
        $author->scaffold('lighthouse', 'Lighthouse');
        file_put_contents(resource_path('views/templates/lighthouse/layout.blade.php'), 'HAND WRITTEN');

        try {
            // A design's name gives the same theme name every run, so the
            // second design for one company arrives at a folder that already
            // exists. It used to write the skeleton straight into it; then it
            // was refused, which was right about the danger and useless about
            // the fix — the model invented worse names, or reached for the
            // theme that was already there and built onto somebody else's.
            $result = (new CreateThemeTool())->execute(['name' => 'Lighthouse', 'kind' => 'landing']);

            $this->assertTrue($result['success'] ?? false, $result['error'] ?? '');
            $this->assertSame('lighthouse-2', $result['theme']);
            $this->assertSame('HAND WRITTEN', file_get_contents(resource_path('views/templates/lighthouse/layout.blade.php')));
            $this->assertStringContainsString('<!doctype html>', file_get_contents(resource_path('views/templates/lighthouse-2/layout.blade.php')));
        } finally {
            $this->cleanUp('lighthouse');
            $this->cleanUp('lighthouse-2');
        }
    }

    public function test_it_keeps_counting_past_a_name_that_is_also_taken(): void
    {
        $author = app(ThemeAuthor::class);
        $author->scaffold('lighthouse', 'Lighthouse');
        $author->scaffold('lighthouse-2', 'Lighthouse 2');

        try {
            $this->assertSame('lighthouse-3', $author->scaffold('Lighthouse', 'Lighthouse'));
        } finally {
            foreach (['lighthouse', 'lighthouse-2', 'lighthouse-3'] as $theme) {
                $this->cleanUp($theme);
            }
        }
    }

    public function test_a_build_may_write_over_the_theme_it_staged_last_time(): void
    {
        $author = app(ThemeAuthor::class);
        $author->scaffold('lighthouse', 'Lighthouse');
        file_put_contents(resource_path('views/templates/lighthouse/layout.blade.php'), 'FROM THE LAST RUN');

        app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->setTheme('lighthouse');

        try {
            $result = (new CreateThemeTool())->execute(['name' => 'Lighthouse', 'kind' => 'landing']);

            $this->assertTrue($result['success'] ?? false, $result['error'] ?? '');
            $this->assertStringContainsString('<!doctype html>', file_get_contents(resource_path('views/templates/lighthouse/layout.blade.php')));

            // And what it replaced is kept, the way a deleted theme is.
            $this->assertSame(
                'FROM THE LAST RUN',
                file_get_contents(storage_path('app/vela-theme-replaced/lighthouse/layout.blade.php'))
            );
        } finally {
            $this->cleanUp('lighthouse');
            \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/vela-theme-replaced/lighthouse'));
        }
    }

    public function test_the_theme_the_site_is_wearing_is_never_written_over(): void
    {
        $author = app(ThemeAuthor::class);
        $author->scaffold('lighthouse', 'Lighthouse');
        file_put_contents(resource_path('views/templates/lighthouse/layout.blade.php'), 'WHAT VISITORS SEE');

        // The build's own theme, and since promoted to the live site.
        app(\VelaBuild\Core\Services\DesignPreviewFrame::class)->setTheme('lighthouse');
        config(['vela.template.active' => 'lighthouse']);

        try {
            $result = (new CreateThemeTool())->execute(['name' => 'Lighthouse', 'kind' => 'landing']);

            // Rewriting it would change the site under whoever is reading it,
            // which is the one thing a build on a preview page must not do —
            // so even its own name is given up when the site is wearing it.
            $this->assertTrue($result['success'] ?? false, $result['error'] ?? '');
            $this->assertSame('lighthouse-2', $result['theme']);
            $this->assertSame('WHAT VISITORS SEE', file_get_contents(resource_path('views/templates/lighthouse/layout.blade.php')));
        } finally {
            $this->cleanUp('lighthouse');
            $this->cleanUp('lighthouse-2');
        }
    }

}
