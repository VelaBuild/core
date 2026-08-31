<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A round of corrections called create_theme twice, switched the site to the
 * theme it had just made, and rewrote seven of its views — against a prompt
 * whose first rule is "Never create_theme again". Asking was not enough, and
 * by the time the round finished, the theme carrying the design was not the
 * one the site was wearing.
 */
class DesignFixRoundToolsTest extends PackageTestCase
{
    use RefreshDatabase;

    public function test_a_fix_round_cannot_make_or_switch_a_theme(): void
    {
        $all = app(ChatToolRegistry::class)->all();
        $correcting = array_column(app(DesignBuilderService::class)->toolsForCorrecting($all), 'name');

        foreach (DesignBuilderService::TOOLS_A_FIX_MAY_NOT_USE as $withheld) {
            $this->assertNotContains($withheld, $correcting);
        }
    }

    public function test_it_still_has_what_correcting_actually_needs(): void
    {
        $all = app(ChatToolRegistry::class)->all();
        $correcting = array_column(app(DesignBuilderService::class)->toolsForCorrecting($all), 'name');

        // Withholding too much would leave a round unable to correct anything.
        foreach (['set_theme_tokens', 'add_designed_section', 'get_page_blocks', 'update_block', 'write_theme_file'] as $needed) {
            $this->assertContains($needed, $correcting);
        }
    }

    /**
     * A name in that list that no longer matches a tool would withhold nothing
     * and read as though it did.
     */
    public function test_every_withheld_tool_is_a_real_one(): void
    {
        $names = array_column(app(ChatToolRegistry::class)->all(), 'name');

        foreach (DesignBuilderService::TOOLS_A_FIX_MAY_NOT_USE as $withheld) {
            $this->assertContains($withheld, $names);
        }
    }
}
