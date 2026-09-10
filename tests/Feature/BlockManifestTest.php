<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\PackageTestCase;
use VelaBuild\Core\Vela;

/**
 * Twenty block types rendered on the public site. Fifteen could be edited.
 *
 * The other five — app_download, code, and the three review blocks — had no
 * form anywhere, so a page carrying one showed "Unknown block type" and its
 * owner could never change a word of it. Nothing reported that; the registry
 * answered "is this editable?" with a regex over page-editor.js, reading back
 * whatever the JS happened to register, so a block was editable by accident
 * rather than by declaration and a block that was neither looked the same as
 * one that was fine.
 *
 * PHP declares it now: `'editor' => 'js'` for the blocks whose editing is
 * genuinely bespoke, `'fields' => [...]` for the ones that are a plain form.
 * These tests hold the two halves together.
 */
class BlockManifestTest extends PackageTestCase
{
    private function blocks(): array
    {
        return app(Vela::class)->blocks()->all();
    }

    private function editorScript(): string
    {
        return file_get_contents(__DIR__ . '/../../public/js/page-editor.js');
    }

    public function test_every_registered_block_can_be_edited(): void
    {
        $registry = app(Vela::class)->blocks();

        foreach (array_keys($this->blocks()) as $name) {
            $this->assertTrue(
                $registry->isEditable($name),
                "The {$name} block renders on the public site but has no editor. A page carrying "
                . 'one shows "Unknown block type" and cannot be changed. Give it either '
                . "'editor' => 'js' with a registerBlockType in page-editor.js, or a 'fields' schema."
            );
        }
    }

    public function test_a_block_claiming_a_hand_written_editor_has_one(): void
    {
        $script = $this->editorScript();

        foreach ($this->blocks() as $name => $config) {
            if (($config['editor'] ?? null) !== 'js') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                "/registerBlockType\(\s*'" . preg_quote($name, '/') . "'/",
                $script,
                "The {$name} block says its editor is hand-written, but page-editor.js does not "
                . 'register one. That claim is what makes it count as editable.'
            );
        }
    }

    public function test_a_block_with_a_schema_is_not_also_hand_written(): void
    {
        $script = $this->editorScript();

        foreach (app(Vela::class)->blocks()->fieldSchemas() as $name => $schema) {
            $this->assertDoesNotMatchRegularExpression(
                "/registerBlockType\(\s*'" . preg_quote($name, '/') . "'/",
                $script,
                "The {$name} block has a field schema and a hand-written editor. Two forms for one "
                . 'block, and which one appears depends on load order.'
            );
        }
    }

    public function test_the_five_blocks_that_had_no_form_now_have_one(): void
    {
        $schemas = app(Vela::class)->blocks()->fieldSchemas();

        foreach (['app_download', 'code', 'review-summary', 'review-carousel', 'review-grid'] as $name) {
            $this->assertArrayHasKey($name, $schemas, "The {$name} block lost its field schema.");
            $this->assertNotEmpty($schemas[$name]['fields']);
        }
    }

    public function test_a_schema_only_names_keys_the_block_actually_stores(): void
    {
        foreach (app(Vela::class)->blocks()->fieldSchemas() as $name => $schema) {
            foreach ($schema['fields'] as $field) {
                $bag = $field['in'] === 'settings' ? 'settings' : 'content';

                $this->assertArrayHasKey(
                    $field['key'],
                    $schema['defaults'][$bag] ?? [],
                    "The {$name} block's form offers '{$field['key']}' under {$bag}, which is not one "
                    . 'of its defaults. A key the view does not read is a control that does nothing.'
                );
            }
        }
    }
}
