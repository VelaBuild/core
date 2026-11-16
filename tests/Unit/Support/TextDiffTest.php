<?php

namespace VelaBuild\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use VelaBuild\Core\Support\TextDiff;

/**
 * The diff page shows a unified diff as code: line numbers on both sides,
 * each removed line opposite the line that replaced it.
 */
class TextDiffTest extends TestCase
{
    private const DIFF = "--- site/cart.blade.php\n+++ plugin/cart\n"
        . "@@ -1,4 +1,5 @@ section\n"
        . "-{{-- @version 1.0.0 --}}\n"
        . "+{{-- @version 1.2.0 --}}\n"
        . " <div>\n"
        . " \n"
        . "+  <p>shipping</p>\n"
        . " </div>\n"
        . "\\ No newline at end of file\n";

    public function test_lines_carry_their_numbers_on_each_side(): void
    {
        $hunks = TextDiff::parse(self::DIFF);

        $this->assertCount(1, $hunks);
        $this->assertSame('section', $hunks[0]['header']);
        $this->assertSame(
            [
                ['del', 1, null], ['add', null, 1], ['context', 2, 2], ['context', 3, 3],
                ['add', null, 4], ['context', 4, 5], ['note', null, null],
            ],
            array_map(fn ($l) => [$l['type'], $l['old'], $l['new']], $hunks[0]['lines'])
        );
        $this->assertSame('', $hunks[0]['lines'][3]['text'], 'an empty unchanged line is kept');
    }

    public function test_side_by_side_puts_a_replaced_line_opposite_its_replacement(): void
    {
        $rows = TextDiff::sideBySide(TextDiff::parse(self::DIFF)[0]['lines']);

        $this->assertSame('{{-- @version 1.0.0 --}}', $rows[0]['left']['text']);
        $this->assertSame('{{-- @version 1.2.0 --}}', $rows[0]['right']['text']);

        // An added line has nothing opposite it.
        $this->assertNull($rows[3]['left']);
        $this->assertSame('  <p>shipping</p>', $rows[3]['right']['text']);

        $this->assertCount(5, $rows, 'the note is not a row');
    }

    public function test_no_difference_is_no_hunks(): void
    {
        $this->assertSame([], TextDiff::parse(''));
        $this->assertSame([], TextDiff::parse(TextDiff::unified("a\n", "a\n", 'x', 'y')));
    }
}
