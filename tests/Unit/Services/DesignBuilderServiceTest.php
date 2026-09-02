<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Services\SiteContext;
use VelaBuild\Core\Services\ScreenshotService;
use VelaBuild\Core\Services\ClaudeTextService;
use VelaBuild\Core\Tests\PackageTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;

class DesignBuilderServiceTest extends PackageTestCase
{

    protected ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDir($file);
            }
        }
        rmdir($dir);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/vela_test_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDir = $dir;
        return $dir;
    }

    private function makeService(): DesignBuilderService
    {
        $aiManager = Mockery::mock(AiProviderManager::class);
        $toolRegistry = app(ChatToolRegistry::class);
        $toolExecutor = app(ChatToolExecutor::class);
        $siteContext = app(SiteContext::class);

        return new DesignBuilderService($aiManager, $toolRegistry, $toolExecutor, $siteContext, app(ScreenshotService::class));
    }

    private function createMinimalPng(string $path): void
    {
        // Minimal valid 1x1 PNG binary
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($path, $png);
    }

    public function test_generates_context_from_folder(): void
    {
        $dir = $this->makeTempDir();
        $this->createMinimalPng($dir . '/banner.png');
        file_put_contents($dir . '/README.md', '# Design Notes\nUse blue primary color.');

        $service = $this->makeService();
        $context = $service->generateContext($dir);

        $this->assertArrayHasKey('assets', $context);
        $this->assertArrayHasKey('instructions', $context);
        $this->assertCount(1, $context['assets']);
        $this->assertCount(1, $context['instructions']);
        $this->assertEquals('banner.png', $context['assets'][0]['file']);
        $this->assertStringContainsString('Design Notes', $context['instructions'][0]['content']);
        $this->assertFileExists($dir . '/context.json');
    }

    public function test_context_skips_unsupported_file_types(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/malware.exe', 'fake executable');
        $this->createMinimalPng($dir . '/banner.png');

        $service = $this->makeService();
        $context = $service->generateContext($dir);

        $files = array_column($context['assets'], 'file');
        $this->assertContains('banner.png', $files);
        $this->assertNotContains('malware.exe', $files);
        $this->assertCount(1, $context['assets']);
    }

    public function test_context_detects_design_role_from_filename(): void
    {
        $dir = $this->makeTempDir();
        $this->createMinimalPng($dir . '/homepage-design.png');
        file_put_contents($dir . '/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $service = $this->makeService();
        $context = $service->generateContext($dir);

        $assetsByFile = [];
        foreach ($context['assets'] as $asset) {
            $assetsByFile[$asset['file']] = $asset;
        }

        $this->assertArrayHasKey('homepage-design.png', $assetsByFile);
        $this->assertArrayHasKey('logo.svg', $assetsByFile);
        $this->assertEquals('design', $assetsByFile['homepage-design.png']['role']);
        $this->assertEquals('asset', $assetsByFile['logo.svg']['role']);
    }

    public function test_progress_callback_is_called(): void
    {
        $dir = $this->makeTempDir();
        $this->createMinimalPng($dir . '/banner.png');

        $service = $this->makeService();

        $messages = [];
        $service->onProgress(function (string $msg) use (&$messages) {
            $messages[] = $msg;
        });

        $service->generateContext($dir);

        $this->assertNotEmpty($messages);
    }

    /**
     * A builder whose photographing and looking are stubbed, so the decision
     * the conversion check makes can be tested without a browser or a model.
     */
    private function makeCheckingService(?array $verdict, bool $canPhotograph = true): DesignBuilderService
    {
        return new class (
            Mockery::mock(AiProviderManager::class),
            app(ChatToolRegistry::class),
            app(ChatToolExecutor::class),
            app(SiteContext::class),
            app(ScreenshotService::class),
            $verdict,
            $canPhotograph
        ) extends DesignBuilderService {
            public array $captured = [];

            public function __construct($a, $b, $c, $d, $e, private ?array $verdict, private bool $canPhotograph)
            {
                parent::__construct($a, $b, $c, $d, $e);
            }

            protected function safeSectionCapture(string $url, string $handle, string $path): ?string
            {
                $this->captured[] = $handle;

                return $this->canPhotograph ? $path : null;
            }

            protected function compareSection(string $before, string $after): ?array
            {
                return $this->verdict;
            }

            public function check(array $toolCall, string $url, \Closure $run): array
            {
                return $this->keepingTheLook($toolCall, $url, $run);
            }
        };
    }

    /** A builder whose readings of the design are scripted. */
    private function makeReadingService(array ...$readings): DesignBuilderService
    {
        return new class (
            Mockery::mock(AiProviderManager::class),
            app(ChatToolRegistry::class),
            app(ChatToolExecutor::class),
            app(SiteContext::class),
            app(ScreenshotService::class),
            $readings
        ) extends DesignBuilderService {
            public int $reads = 0;

            public function __construct($a, $b, $c, $d, $e, private array $readings)
            {
                parent::__construct($a, $b, $c, $d, $e);
            }

            protected function readDesignSectionsOnce(array $context, string $designPath): array
            {
                return $this->readings[$this->reads++] ?? [];
            }
        };
    }

    private function sections(int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = ['label' => 'Section ' . $i, 'what' => ''];
        }

        return $out;
    }

    public function test_the_designs_colours_are_measured_rather_than_judged_by_eye(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('gd is required to build the test design');
        }

        $dir = $this->makeTempDir();

        // Three quarters white, a fifth navy, a twentieth teal — the shape of a
        // page with a dark header and one band of colour in it.
        $image = imagecreatetruecolor(400, 400);
        imagefilledrectangle($image, 0, 0, 399, 399, imagecolorallocate($image, 252, 253, 253));
        imagefilledrectangle($image, 0, 0, 399, 79, imagecolorallocate($image, 32, 42, 57));
        imagefilledrectangle($image, 0, 80, 399, 99, imagecolorallocate($image, 38, 93, 104));
        imagepng($image, $dir . '/design.png');
        imagedestroy($image);

        $palette = $this->makeService()->readDesignPalette(
            ['assets' => [['role' => 'design', 'file' => 'design.png']]],
            $dir
        );

        $this->assertNotEmpty($palette);
        $this->assertSame('#fcfdfd', $palette[0]['hex'], 'most of the picture first');
        $this->assertSame('#202a39', $palette[1]['hex']);
        $this->assertSame('#265d68', $palette[2]['hex']);

        // The real value, not the bucket it was grouped into: a build told
        // "#203040" writes a colour the design does not contain.
        $this->assertGreaterThan(60, $palette[0]['share']);
        $this->assertEqualsWithDelta(20, $palette[1]['share'], 2.0);
    }

    /** Reach a private method, to read a prompt without driving a whole build. */
    private function invokePrivate(DesignBuilderService $service, string $method, array $args)
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $args);
    }

    public function test_a_build_with_no_page_of_its_own_is_refused_rather_than_aimed_at_the_homepage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('needs a page of its own');

        $this->invokePrivate($this->makeService(), 'buildSystemPrompt', [['assets' => []], false]);
    }

    public function test_the_build_is_told_to_work_on_the_page_it_was_given(): void
    {
        $context = ['assets' => [], 'target_page' => ['id' => 42, 'slug' => 'design-preview']];

        $prompt = $this->invokePrivate($this->makeService(), 'buildSystemPrompt', [$context, false]);

        $this->assertStringContainsString('page id 42', $prompt);
        $this->assertStringContainsString('/design-preview', $prompt);
        // The instruction that used to stand in for a target page.
        $this->assertStringNotContainsString('delete every row of it', $prompt);
    }

    public function test_a_fix_round_is_not_told_that_what_it_does_not_recognise_is_the_installs_own(): void
    {
        $context = ['assets' => [], 'target_page' => ['id' => 7, 'slug' => 'design-preview']];

        $prompt = $this->invokePrivate($this->makeService(), 'buildSystemPrompt', [$context, true]);

        $this->assertStringContainsString('page id 7', $prompt);
        $this->assertStringNotContainsString("install's own homepage", $prompt);
    }

    public function test_an_answer_is_given_the_room_a_written_section_needs(): void
    {
        $service = $this->makeService();

        config(['vela.ai.chat.max_output_tokens' => 16384]);
        $this->assertSame(16384, $this->invokePrivate($service, 'answerTokens', []));

        // Never below what it used to ask for, however the site is configured:
        // a section carries its markup and its stylesheet in one answer.
        config(['vela.ai.chat.max_output_tokens' => 512]);
        $this->assertSame(4096, $this->invokePrivate($service, 'answerTokens', []));
    }

    public function test_an_answer_that_ran_out_of_room_is_recognised_whichever_provider_said_so(): void
    {
        $service = $this->makeService();

        $this->assertTrue($this->invokePrivate($service, 'wasCutOff', [['finish_reason' => 'max_tokens']]));
        $this->assertTrue($this->invokePrivate($service, 'wasCutOff', [['finish_reason' => 'length']]));
        $this->assertTrue($this->invokePrivate($service, 'wasCutOff', [['finish_reason' => 'MAX_TOKENS']]));
        $this->assertFalse($this->invokePrivate($service, 'wasCutOff', [['finish_reason' => 'end_turn']]));
        // A provider that says nothing is not a provider reporting truncation.
        $this->assertFalse($this->invokePrivate($service, 'wasCutOff', [['content' => 'done']]));
        $this->assertFalse($this->invokePrivate($service, 'wasCutOff', [null]));
    }

    public function test_a_build_moves_to_the_model_named_for_its_provider(): void
    {
        config(['vela.ai.chat.design_models' => ['anthropic' => 'claude-opus-5']]);

        $provider = Mockery::mock(ClaudeTextService::class);
        $provider->shouldReceive('useModel')->once()->with('claude-opus-5');

        $this->invokePrivate($this->makeService(), 'useDesignModel', [$provider]);
        $this->addToAssertionCount(1);
    }

    public function test_a_provider_with_no_design_model_named_is_left_where_it_is(): void
    {
        config(['vela.ai.chat.design_models' => ['anthropic' => '']]);

        $provider = Mockery::mock(ClaudeTextService::class);
        $provider->shouldNotReceive('useModel');

        $this->invokePrivate($this->makeService(), 'useDesignModel', [$provider]);
        $this->addToAssertionCount(1);
    }

    /** A page of the site, and the build's own page, to aim tool calls at. */
    private function twoPages(): array
    {
        $target = Page::create([
            'title' => 'Design preview', 'slug' => 'design-preview',
            'status' => 'unlisted', 'locale' => config('vela.primary_language', 'en'),
        ]);
        $other = Page::create([
            'title' => 'Design preview 1', 'slug' => 'design-preview-1',
            'status' => 'draft', 'locale' => config('vela.primary_language', 'en'),
        ]);

        return [$target, $other];
    }

    public function test_a_section_written_onto_another_page_is_refused(): void
    {
        [$target, $other] = $this->twoPages();
        $context = ['target_page' => ['id' => $target->id, 'slug' => $target->slug]];

        $refusal = $this->invokePrivate($this->makeService(), 'refuseAnotherPage', [
            ['name' => 'add_designed_section', 'arguments' => ['page_id' => $other->id, 'html' => '<p>x</p>']],
            $context,
        ]);

        $this->assertIsArray($refusal);
        $this->assertStringContainsString('page id ' . $target->id, $refusal['error']);
    }

    public function test_a_row_on_another_page_is_refused_through_the_row_it_names(): void
    {
        [$target, $other] = $this->twoPages();
        $row = PageRow::create(['page_id' => $other->id, 'name' => 'Hero', 'css_class' => '', 'order_column' => 0]);
        $context = ['target_page' => ['id' => $target->id, 'slug' => $target->slug]];

        $refusal = $this->invokePrivate($this->makeService(), 'refuseAnotherPage', [
            ['name' => 'add_block', 'arguments' => ['row_id' => $row->id, 'type' => 'text']],
            $context,
        ]);

        $this->assertIsArray($refusal);
    }

    public function test_the_page_the_build_was_given_is_let_through(): void
    {
        [$target] = $this->twoPages();
        $row = PageRow::create(['page_id' => $target->id, 'name' => 'Hero', 'css_class' => '', 'order_column' => 0]);
        $context = ['target_page' => ['id' => $target->id, 'slug' => $target->slug]];
        $service = $this->makeService();

        $this->assertNull($this->invokePrivate($service, 'refuseAnotherPage', [
            ['name' => 'add_designed_section', 'arguments' => ['page_slug' => 'design-preview']], $context,
        ]));
        $this->assertNull($this->invokePrivate($service, 'refuseAnotherPage', [
            ['name' => 'add_block', 'arguments' => ['row_id' => $row->id]], $context,
        ]));
        // A tool that touches no page at all, and one whose own error is the
        // better one to return.
        $this->assertNull($this->invokePrivate($service, 'refuseAnotherPage', [
            ['name' => 'set_theme_tokens', 'arguments' => ['accent' => '#000']], $context,
        ]));
        $this->assertNull($this->invokePrivate($service, 'refuseAnotherPage', [
            ['name' => 'update_custom_css', 'arguments' => ['scope' => 'site', 'css' => 'a{}']], $context,
        ]));
    }

    public function test_a_page_created_for_a_menu_link_is_not_itself_refused(): void
    {
        [$target] = $this->twoPages();
        $context = ['target_page' => ['id' => $target->id, 'slug' => $target->slug]];

        $this->assertNull($this->invokePrivate($this->makeService(), 'refuseAnotherPage', [
            ['name' => 'create_page', 'arguments' => ['title' => 'Docs', 'slug' => 'docs']], $context,
        ]));
    }

    /** Fill a picture with bands of colour, each a share of its height. */
    private function bandedImage(string $path, array $bands): void
    {
        $image = imagecreatetruecolor(200, 200);
        $y = 0;

        foreach ($bands as $hex => $share) {
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
            $height = (int) round(200 * $share);
            imagefilledrectangle($image, 0, $y, 199, min(199, $y + $height - 1), imagecolorallocate($image, $r, $g, $b));
            $y += $height;
        }

        imagepng($image, $path);
        imagedestroy($image);
    }

    public function test_a_colour_the_design_has_and_the_page_has_not_is_counted(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('gd is required to build the test pictures');
        }

        $dir = $this->makeTempDir();
        $this->bandedImage($dir . '/design.png', ['#fcfdfd' => 0.7, '#202a39' => 0.3]);
        // What five builds produced: near-black and bright blue where the
        // design is navy.
        $this->bandedImage($dir . '/page.png', ['#fcfdfd' => 0.7, '#1b1b1b' => 0.2, '#3399ff' => 0.1]);

        $difference = $this->makeService()->compareColour($dir . '/design.png', $dir . '/page.png');

        $this->assertStringContainsString('#202a39', $difference);
        $this->assertStringContainsString('% of the design', $difference);
    }

    public function test_a_page_that_carries_the_designs_colour_is_not_complained_about(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('gd is required to build the test pictures');
        }

        $dir = $this->makeTempDir();
        $this->bandedImage($dir . '/design.png', ['#fcfdfd' => 0.7, '#202a39' => 0.3]);
        // The same colours, a shade off, as a screenshot always is.
        $this->bandedImage($dir . '/page.png', ['#fbfcfc' => 0.7, '#222c3b' => 0.3]);

        $this->assertSame('', $this->makeService()->compareColour($dir . '/design.png', $dir . '/page.png'));
    }

    public function test_a_design_that_cannot_be_read_costs_nothing(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/design.png', 'not a picture');

        $this->assertSame([], $this->makeService()->readDesignPalette(
            ['assets' => [['role' => 'design', 'file' => 'design.png']]],
            $dir
        ));

        $this->assertSame([], $this->makeService()->readDesignPalette(['assets' => []], $dir));
    }

    public function test_a_short_reading_of_the_design_is_taken_again(): void
    {
        // Read three times, the same design gave four sections, four, then one
        // — a hero alone, on a page with a row of cards, a call-to-action strip
        // and a row of articles under it. The build works down this list, so a
        // short answer is a short site.
        $service = $this->makeReadingService($this->sections(1), $this->sections(4));

        $this->assertCount(4, $service->readDesignSections([], '/tmp'));
        $this->assertSame(2, $service->reads);
    }

    public function test_a_full_reading_is_not_second_guessed(): void
    {
        // The re-read is a floor, not a tie-break: an answer that already saw
        // the page is not paid for twice.
        $service = $this->makeReadingService($this->sections(3), $this->sections(9));

        $this->assertCount(3, $service->readDesignSections([], '/tmp'));
        $this->assertSame(1, $service->reads);
    }

    public function test_the_fuller_of_two_short_readings_wins(): void
    {
        $service = $this->makeReadingService($this->sections(2), $this->sections(1));

        $this->assertCount(2, $service->readDesignSections([], '/tmp'));
        $this->assertSame(2, $service->reads);
    }

    public function test_a_conversion_that_kept_the_look_is_left_alone(): void
    {
        $service = $this->makeCheckingService(['same' => true, 'differences' => 'nothing']);

        $result = $service->check(
            ['name' => 'convert_section_to_block', 'arguments' => ['row_id' => 7, 'type' => 'hero']],
            'http://localhost',
            fn () => ['success' => true, 'converted' => true]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['#row-7', '#row-7'], $service->captured, 'before and after, by the row id the theme renders');
    }

    public function test_a_conversion_that_changed_the_look_comes_back_as_an_error(): void
    {
        $service = $this->makeCheckingService(['same' => false, 'differences' => 'the three cards became a list']);

        $result = $service->check(
            ['name' => 'convert_section_to_block', 'arguments' => ['row_id' => 7, 'type' => 'icon_box']],
            'http://localhost',
            fn () => ['success' => true, 'converted' => true]
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('three cards became a list', $result['error']);
        $this->assertFalse($result['converted']);
    }

    public function test_a_conversion_stands_where_there_is_nothing_to_photograph(): void
    {
        // No Chrome, or a page the build cannot reach. An unmeasurable change is
        // not a failed one; refusing it would take the feature away from exactly
        // the sites most likely to need it.
        $service = $this->makeCheckingService(['same' => false, 'differences' => 'everything'], canPhotograph: false);

        $result = $service->check(
            ['name' => 'convert_section_to_block', 'arguments' => ['row_id' => 7, 'type' => 'hero']],
            'http://localhost',
            fn () => ['success' => true, 'converted' => true]
        );

        $this->assertTrue($result['success']);
    }

    public function test_a_conversion_that_the_tool_refused_is_not_photographed_afterwards(): void
    {
        $service = $this->makeCheckingService(['same' => false, 'differences' => 'x']);

        $result = $service->check(
            ['name' => 'convert_section_to_block', 'arguments' => ['row_id' => 7, 'type' => 'pricing_tiers']],
            'http://localhost',
            fn () => ['error' => 'pricing_tiers is not a shape a section can be read into.']
        );

        $this->assertSame('pricing_tiers is not a shape a section can be read into.', $result['error']);
        $this->assertSame(['#row-7'], $service->captured, 'the "after" picture is of a change that never happened');
    }

    public function test_every_other_tool_call_goes_straight_through(): void
    {
        $service = $this->makeCheckingService(['same' => false, 'differences' => 'x']);

        $result = $service->check(
            ['name' => 'add_designed_section', 'arguments' => ['name' => 'Hero']],
            'http://localhost',
            fn () => ['success' => true]
        );

        $this->assertTrue($result['success']);
        $this->assertSame([], $service->captured);
    }
}
