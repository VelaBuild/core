<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\DesignDestination;
use VelaBuild\Core\Services\DesignPreviewFrame;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A design could only ever become the homepage.
 *
 * Right for the first design a site is given and wrong for every one after
 * it: somebody who has their front page and wants an About page out of a
 * second mockup had nowhere to say so, and a build let loose on one would
 * have written a second theme over the site's own on the way — a theme is
 * site-wide, so a mockup of one inside page would have redressed every page
 * on the site.
 *
 * So a page build writes sections and nothing else, and the withholding is in
 * the tool list rather than only in the prompt. That distinction is the whole
 * lesson of this feature: a rule the tools do not enforce is a rule the model
 * breaks under pressure.
 */
class DesignPageBuildTest extends PackageTestCase
{
    use RefreshDatabase;

    private function signInAsAdmin(): void
    {
        $this->signIn();
        Gate::define('config_access', fn () => true);
        Gate::define('config_edit', fn () => true);
    }

    /** @return array<int, array<string, mixed>> */
    private function everyTool(): array
    {
        return array_map(
            fn ($name) => ['name' => $name],
            array_merge(
                DesignBuilderService::TOOLS_A_SECTIONS_BUILD_MAY_NOT_USE,
                ['add_designed_section', 'add_row', 'add_block', 'update_page', 'get_page_blocks']
            )
        );
    }

    /** @return array<int, string> */
    private function names(array $tools): array
    {
        return array_column($tools, 'name');
    }

    public function test_a_page_build_is_not_given_the_tools_that_change_the_frame(): void
    {
        $builder = app(DesignBuilderService::class);
        $given = $this->names($builder->toolsForBuilding($this->everyTool(), ['build_scope' => 'sections']));

        foreach (['create_theme', 'set_theme_tokens', 'set_menu', 'use_theme_for_preview', 'write_theme_file', 'update_site_config'] as $withheld) {
            $this->assertNotContains($withheld, $given, $withheld . ' reached a page build');
        }

        // What it IS for has to survive the filtering, or the build has
        // nothing left to do.
        $this->assertContains('add_designed_section', $given);
        $this->assertContains('add_block', $given);
        $this->assertContains('update_page', $given);
    }

    public function test_a_homepage_build_still_writes_its_own_frame(): void
    {
        $builder = app(DesignBuilderService::class);
        $given = $this->names($builder->toolsForBuilding($this->everyTool(), []));

        $this->assertContains('create_theme', $given);
        $this->assertContains('set_theme_tokens', $given);
        $this->assertContains('set_menu', $given);
        // The one thing no build may do, page or homepage: dress the live site
        // in a theme nobody has agreed to yet.
        $this->assertNotContains('switch_template', $given);
    }

    public function test_a_round_of_corrections_on_a_page_cannot_reach_the_frame_either(): void
    {
        $builder = app(DesignBuilderService::class);

        // The QA loop is where the pressure is, and every guard in this
        // feature was written down after a fix round went around a prompt.
        $given = $this->names($builder->toolsForCorrecting($this->everyTool(), ['build_scope' => 'sections']));

        $this->assertNotContains('set_theme_tokens', $given);
        $this->assertNotContains('set_menu', $given);
        $this->assertNotContains('write_theme_file', $given);
        $this->assertContains('add_designed_section', $given);
    }

    public function test_the_prompt_for_a_page_build_does_not_ask_for_a_theme(): void
    {
        $page = Page::create([
            'title' => 'Design preview',
            'slug' => 'design-preview',
            'locale' => 'en',
            'status' => 'unlisted',
        ]);

        $context = [
            'instructions' => [],
            'assets' => [],
            'build_scope' => 'sections',
            'target_page' => ['id' => $page->id, 'slug' => $page->slug, 'title' => $page->title],
        ];

        $builder = app(DesignBuilderService::class);
        $method = new \ReflectionMethod($builder, 'buildSystemPrompt');
        $method->setAccessible(true);

        $forAPage = $method->invoke($builder, $context, false);
        $forTheHomepage = $method->invoke($builder, ['build_scope' => 'full'] + $context, false);

        $this->assertStringNotContainsString('create_theme', $forAPage);
        $this->assertStringContainsString('add_designed_section', $forAPage);
        $this->assertStringContainsString('ONE PAGE', $forAPage);

        // The same call with the scope removed is the build that writes a
        // frame — so the difference is the scope and nothing else.
        $this->assertStringContainsString('create_theme', $forTheHomepage);
    }

    public function test_a_destination_of_a_page_makes_the_build_a_sections_build(): void
    {
        $destination = app(DesignDestination::class);

        $this->assertFalse($destination->isSectionsOnly());
        $this->assertSame(DesignDestination::HOMEPAGE, $destination->read()['mode']);

        $destination->setPage('about', 'About us');

        $this->assertTrue($destination->isSectionsOnly());
        $this->assertSame('about', $destination->read()['slug']);

        // "home" is the homepage however it is arrived at, or a page build
        // would be handed the front page with the frame tools taken away.
        $destination->setPage('home', 'Home');

        $this->assertFalse($destination->isSectionsOnly());
    }

    public function test_keeping_a_design_puts_it_on_the_chosen_page_and_parks_what_was_there(): void
    {
        $this->signInAsAdmin();

        Page::create(['title' => 'Home', 'slug' => 'home', 'locale' => 'en', 'status' => 'published']);
        $about = Page::create(['title' => 'About us', 'slug' => 'about', 'locale' => 'en', 'status' => 'published']);
        $preview = Page::create([
            'title' => 'Design preview',
            'slug' => 'design-preview',
            'locale' => 'en',
            'status' => 'unlisted',
        ]);

        app(DesignDestination::class)->setPage('about', 'About us');

        $this->post(route('vela.admin.settings.design-builder.use'))->assertRedirect();

        $this->assertSame('about', $preview->fresh()->slug);
        $this->assertSame('published', $preview->fresh()->status);
        // The page is known by its name in the navigation and in every link to
        // it; a design supplies the words on a page, not what it is called.
        $this->assertSame('About us', $preview->fresh()->title);

        $this->assertNotSame('about', $about->fresh()->slug);
        $this->assertSame('unlisted', $about->fresh()->status);

        // The homepage is not part of this, and nothing about the frame is.
        $this->assertSame('home', Page::find(Page::where('slug', 'home')->value('id'))->slug);
    }

    public function test_a_design_for_a_page_that_does_not_exist_yet_becomes_one(): void
    {
        $this->signInAsAdmin();

        $preview = Page::create([
            'title' => 'Design preview',
            'slug' => 'design-preview',
            'locale' => 'en',
            'status' => 'unlisted',
        ]);

        app(DesignDestination::class)->setPage('pricing', 'Pricing');

        $this->post(route('vela.admin.settings.design-builder.use'))->assertRedirect();

        $this->assertSame('pricing', $preview->fresh()->slug);
        $this->assertSame('published', $preview->fresh()->status);
        $this->assertSame('Pricing', $preview->fresh()->title);

        // Nothing was displaced, so putting the site back means taking the
        // page off it again — not restoring something that never existed.
        $this->post(route('vela.admin.settings.design-builder.restore'))->assertRedirect();

        $this->assertNull(Page::where('slug', 'pricing')->first());
        $this->assertSame('unlisted', $preview->fresh()->status);
    }

    public function test_putting_the_site_back_returns_the_page_that_was_replaced(): void
    {
        $this->signInAsAdmin();

        $about = Page::create(['title' => 'About us', 'slug' => 'about', 'locale' => 'en', 'status' => 'published']);
        $preview = Page::create([
            'title' => 'Design preview',
            'slug' => 'design-preview',
            'locale' => 'en',
            'status' => 'unlisted',
        ]);

        app(DesignDestination::class)->setPage('about', 'About us');

        $this->post(route('vela.admin.settings.design-builder.use'))->assertRedirect();
        $this->post(route('vela.admin.settings.design-builder.restore'))->assertRedirect();

        $this->assertSame('about', $about->fresh()->slug);
        $this->assertSame('published', $about->fresh()->status);
        // And the design is still there to go forward again with.
        $this->assertNotSame('about', $preview->fresh()->slug);
        $this->assertSame('unlisted', $preview->fresh()->status);
    }

    public function test_the_page_offers_the_three_destinations_and_names_the_one_chosen(): void
    {
        // Fetched and read rather than trusted: two bugs in this feature were
        // found by looking at the rendered admin page while every unit test
        // was green.
        $this->signInAsAdmin();

        Page::create(['title' => 'About us', 'slug' => 'about', 'locale' => 'en', 'status' => 'published']);
        Page::create(['title' => 'Design preview', 'slug' => 'design-preview', 'locale' => 'en', 'status' => 'unlisted']);

        app(DesignDestination::class)->setPage('about', 'About us');

        $response = $this->get(route('vela.admin.settings.design-builder.index'));

        $response->assertOk();
        $response->assertSee('This design is for', false);
        $response->assertSee('name="destination_page"', false);
        $response->assertSee('name="destination_title"', false);
        // The choice already made comes back chosen, so opening the page and
        // pressing Build cannot silently send a build somewhere else.
        $response->assertSee('About us (/about)', false);
        // Never itself: the preview page is where a build works.
        $response->assertDontSee('(/design-preview)', false);

        // And the button that keeps it says where it is going, or keeping one
        // is a press into the dark.
        $this->assertSame('Use this as "About us"', app(DesignDestination::class)->keepLabel());
    }

    public function test_an_empty_preview_frame_does_not_stand_in_for_the_site(): void
    {
        // A page build stages no theme on purpose. If the frame activated
        // anyway, the menus a PREVIOUS design left on the peg would become the
        // header the page is judged against.
        $frame = app(DesignPreviewFrame::class);
        $frame->forgetTheme();

        $frame->activate();

        $this->assertFalse($frame->isActive());
    }
}
