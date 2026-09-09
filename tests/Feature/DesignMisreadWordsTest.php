<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use VelaBuild\Core\Services\DesignBuilderService;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A build that has misread the design says nothing about it. Every guard asks
 * whether the page matches the design, and a word read wrong is the same shape
 * in the same place as the right one — so "Wluctn znoe." went onto a page and
 * the run reported success.
 *
 * What the model is told, and what it is not: the words come back with the
 * call's own result, and the call stands. Refusing it would send a fix round
 * off to rewrite wording it had read correctly, which is a failure this
 * feature has already had.
 */
class DesignMisreadWordsTest extends PackageTestCase
{
    use RefreshDatabase;

    private function builder(): DesignBuilderService
    {
        return app(DesignBuilderService::class);
    }

    public function test_it_hands_back_the_words_that_are_not_words(): void
    {
        $builder = $this->builder();

        $result = $builder->sayWhatLooksMisread(
            [
                'name' => 'add_designed_section',
                'arguments' => ['name' => 'Hero', 'html' => '<h1>Wluctn znoe</h1><p>Real-time monitoring.</p>'],
            ],
            ['success' => true, 'row_id' => 7],
            []
        );

        $this->assertSame(['Wluctn', 'znoe'], $result['words_to_check']);
        // The call stands: this is a suspicion, and the design is the only
        // thing that can settle it.
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('leave them exactly as they are', $result['words_to_check_note']);

        // And kept for the person watching, against the section they are in.
        $this->assertSame(['wluctn' => 'Hero', 'znoe' => 'Hero'], $builder->misreadWords());
    }

    public function test_a_name_the_brief_gave_it_is_not_a_misreading(): void
    {
        $result = $this->builder()->sayWhatLooksMisread(
            [
                'name' => 'add_designed_section',
                'arguments' => ['name' => 'Hero', 'html' => '<h1>mntn</h1>'],
            ],
            ['success' => true],
            ['instructions' => [['file' => 'brief.md', 'content' => 'A hiking club called mntn.']]]
        );

        $this->assertArrayNotHasKey('words_to_check', $result);
    }

    public function test_it_stays_out_of_a_call_that_writes_no_wording(): void
    {
        $builder = $this->builder();

        // A stylesheet is not wording, and its class names are short on
        // purpose — reported here, every build would end on a list of them.
        $result = $builder->sayWhatLooksMisread(
            ['name' => 'update_custom_css', 'arguments' => ['css' => '.fb-hdr .btn-lg{--bg:#fff}']],
            ['success' => true],
            []
        );

        $this->assertArrayNotHasKey('words_to_check', $result);
        $this->assertSame([], $builder->misreadWords());
    }

    public function test_it_says_nothing_about_a_call_that_failed(): void
    {
        $result = $this->builder()->sayWhatLooksMisread(
            ['name' => 'add_designed_section', 'arguments' => ['name' => 'Hero', 'html' => '<h1>Wluctn</h1>']],
            ['error' => 'This page already has a section called "Hero".'],
            []
        );

        $this->assertArrayNotHasKey('words_to_check', $result);
    }
}
