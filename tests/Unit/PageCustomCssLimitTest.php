<?php

namespace VelaBuild\Core\Tests\Unit;

use Illuminate\Support\Facades\Validator;
use VelaBuild\Core\Http\Requests\StorePageRequest;
use VelaBuild\Core\Http\Requests\UpdatePageRequest;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A page that carries an imported section holds that section's own stylesheet
 * — well past the 65000 characters the old TEXT column allowed. The limit
 * outlived the column: the page could be built by the chatbot but the moment
 * its owner opened it in the admin and pressed save, validation rejected the
 * CSS that was already in the database.
 */
class PageCustomCssLimitTest extends PackageTestCase
{
    /**
     * The rule declared for one field.
     *
     * Read from the class source: rules() on the update request resolves a
     * bound route model, and only these two entries are under test.
     */
    private function ruleFor(string $requestClass, string $field): string
    {
        $source = file_get_contents((new \ReflectionClass($requestClass))->getFileName());
        preg_match("/'" . $field . "'\s*=>\s*'([^']+)'/", $source, $matches);

        return $matches[1] ?? '';
    }

    public function test_a_page_may_hold_an_imported_sections_stylesheet(): void
    {
        $css = str_repeat('.vela-import-abc .card{padding:16px;border-radius:8px}', 4000); // ~200KB

        foreach ([StorePageRequest::class, UpdatePageRequest::class] as $request) {
            $rule = $this->ruleFor($request, 'custom_css');
            $this->assertNotSame('', $rule, "no custom_css rule found in {$request}");

            $validator = Validator::make(['custom_css' => $css], ['custom_css' => $rule]);

            $this->assertFalse(
                $validator->fails(),
                "{$request} rejects a stylesheet the section importer itself writes"
            );
        }
    }

    public function test_custom_js_still_stops_at_what_its_column_holds(): void
    {
        // custom_js is a TEXT column, which truncates silently past ~64KB.
        $js = str_repeat('a', 70_000);

        foreach ([StorePageRequest::class, UpdatePageRequest::class] as $request) {
            $validator = Validator::make(['custom_js' => $js], ['custom_js' => $this->ruleFor($request, 'custom_js')]);

            $this->assertTrue($validator->fails(), "{$request} would let custom_js be truncated by the database");
        }
    }
}
