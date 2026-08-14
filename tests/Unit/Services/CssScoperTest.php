<?php

namespace VelaBuild\Core\Tests\Unit\Services;

use VelaBuild\Core\Services\AiChat\CssScoper;
use VelaBuild\Core\Tests\PackageTestCase;

class CssScoperTest extends PackageTestCase
{
    private string $html = '<div class="vela-import-abc"><section class="hero"><h1 class="title">Hi</h1><a class="btn">Go</a></section></div>';

    private function scope(string $css, string $url = 'https://acme.example/landing/'): array
    {
        return (new CssScoper())->scope($css, $this->html, 'vela-import-abc', $url);
    }

    public function test_every_kept_rule_sits_under_the_wrapper(): void
    {
        $out = $this->scope('.hero { padding: 80px 0 } .title { font-size: 48px }')['css'];

        $this->assertStringContainsString('.vela-import-abc .hero', $out);
        $this->assertStringContainsString('padding: 80px 0', $out);
        $this->assertStringNotContainsString("\n.hero", "\n" . $out);
    }

    public function test_a_single_compound_selector_also_matches_the_wrapper_itself(): void
    {
        // The imported section's own element becomes the wrapper, so a rule
        // written for it has to survive as .wrapper.hero too.
        $out = $this->scope('.hero { color: red }')['css'];

        $this->assertStringContainsString('.vela-import-abc.hero', $out);
    }

    public function test_page_level_selectors_become_the_wrapper(): void
    {
        $out = $this->scope('body { background: #101014; font-family: Inter }')['css'];

        $this->assertStringContainsString('.vela-import-abc{', str_replace(' {', '{', $out));
        $this->assertStringContainsString('#101014', $out);
    }

    public function test_rules_that_match_nothing_in_the_markup_are_dropped(): void
    {
        $result = $this->scope('.hero { color: red } .newsletter-popup { display: block } #cart-drawer { width: 0 }');

        $this->assertStringNotContainsString('newsletter-popup', $result['css']);
        $this->assertStringNotContainsString('cart-drawer', $result['css']);
        $this->assertSame(2, $result['rules_dropped']);
    }

    public function test_media_queries_are_kept_and_scoped_inside(): void
    {
        $out = $this->scope('@media (max-width: 600px) { .hero { padding: 20px } .unused-thing { color: red } }')['css'];

        $this->assertStringContainsString('@media (max-width: 600px)', $out);
        $this->assertStringContainsString('.vela-import-abc .hero', $out);
        $this->assertStringNotContainsString('unused-thing', $out);
    }

    public function test_empty_media_queries_do_not_survive(): void
    {
        $out = $this->scope('@media print { .unused-thing { color: red } }')['css'];

        $this->assertStringNotContainsString('@media print', $out);
    }

    public function test_font_face_and_keyframes_are_kept_whole(): void
    {
        $out = $this->scope('@font-face { font-family: Brand; src: url(/f/brand.woff2) } @keyframes fade { from { opacity: 0 } }')['css'];

        $this->assertStringContainsString('@font-face', $out);
        $this->assertStringContainsString('@keyframes fade', $out);
        $this->assertStringContainsString('https://acme.example/f/brand.woff2', $out);
    }

    public function test_imports_are_dropped_and_relative_urls_resolved(): void
    {
        $result = $this->scope('@import url("other.css"); .hero { background: url(../img/bg.jpg) }');

        $this->assertStringNotContainsString('@import', $result['css']);
        $this->assertStringContainsString('https://acme.example/img/bg.jpg', $result['css']);
    }

    public function test_comments_never_reach_the_page(): void
    {
        $out = $this->scope('/* proprietary notice */ .hero { color: red }')['css'];

        $this->assertStringNotContainsString('proprietary', $out);
    }

    public function test_output_is_capped_on_a_rule_boundary(): void
    {
        $css = str_repeat('.hero { padding: 10px; margin: 10px; border: 1px solid #abcdef; }' . "\n", 7000);

        $result = $this->scope($css);

        $this->assertTrue($result['truncated']);
        $this->assertStringEndsWith('}', $result['css']);
        $this->assertLessThan(210_000, strlen($result['css']));
    }

    public function test_escaped_utility_class_names_are_matched_not_mangled(): void
    {
        // Tailwind writes .py-0\.5 and .focus-visible\:outline-2; reading the
        // name up to the backslash gave "py-0" and "focus-visible", which no
        // markup carries — so every utility rule looked unused and a copied
        // page arrived with almost none of its styling.
        $html = '<div class="vela-import-abc"><a class="py-0.5 focus-visible:outline-2 md:flex">Go</a></div>';

        $result = (new CssScoper())->scope(
            '.py-0\.5{padding:2px 0}.focus-visible\:outline-2:focus-visible{outline-width:2px}.md\:flex{display:flex}.py-9{padding:36px}',
            $html,
            'vela-import-abc'
        );

        $this->assertSame(3, $result['rules_kept']);
        $this->assertStringContainsString('padding:2px 0', $result['css']);
        $this->assertStringContainsString('outline-width:2px', $result['css']);
        $this->assertStringNotContainsString('padding:36px', $result['css']);
    }

    public function test_a_negated_class_inside_not_does_not_drop_the_rule(): void
    {
        $out = $this->scope('.hero:not(.is-collapsed) { display: block }')['css'];

        $this->assertStringContainsString('display: block', $out);
    }

    public function test_cascade_layers_are_flattened_so_the_host_site_does_not_outrank_them(): void
    {
        // A layered rule loses to every unlayered rule on the page, and the
        // site's own stylesheet is unlayered — so a section kept inside
        // @layer had its spacing wiped by the template's reset.
        $out = $this->scope('@layer utilities{.hero{padding:40px}}')['css'];

        $this->assertStringNotContainsString('@layer', $out);
        $this->assertStringContainsString('.vela-import-abc .hero', $out);
        $this->assertStringContainsString('padding:40px', $out);
    }

    public function test_an_unterminated_at_rule_still_yields_its_contents(): void
    {
        // A stylesheet cut short mid-file: the whole thing is wrapped in one
        // @layer, so bailing out at the unclosed brace lost every rule in it.
        $out = $this->scope('@layer utilities{.hero{padding:40px}.title{font-size:32px}')['css'];

        $this->assertStringContainsString('padding:40px', $out);
        $this->assertStringContainsString('font-size:32px', $out);
    }

    public function test_classes_holding_an_ampersand_survive_html_escaping(): void
    {
        // Saved markup writes the class as `[&amp;_input]:pl-3` while the
        // stylesheet spells it `[&_input]:pl-3`. Compared raw, every arbitrary
        // variant looked unused — which on a page of form controls is most of
        // their styling, and the copied form rendered as bare browser inputs.
        $html = '<div class="vela-import-abc"><div class="[&amp;_input]:pl-3 [&amp;&gt;*]:gap-4"><input></div></div>';

        $result = (new CssScoper())->scope(
            '.\[\&_input\]\:pl-3 input{padding-left:12px}.\[\&\>\*\]\:gap-4>*{gap:16px}.\[\&_video\]\:hidden video{display:none}',
            $html,
            'vela-import-abc'
        );

        $this->assertSame(2, $result['rules_kept']);
        $this->assertStringContainsString('padding-left:12px', $result['css']);
        $this->assertStringContainsString('gap:16px', $result['css']);
        $this->assertStringNotContainsString('display:none', $result['css']);
    }

    public function test_a_tag_selector_is_not_glued_onto_the_wrapper_class(): void
    {
        $out = (new CssScoper())->scope(
            'input{padding:8px}',
            '<div class="vela-import-abc"><input name="email"></div>',
            'vela-import-abc'
        )['css'];

        // `.vela-import-abcinput` matches nothing; it was pure weight.
        $this->assertStringContainsString('.vela-import-abc input', $out);
        $this->assertStringNotContainsString('.vela-import-abcinput', $out);
    }
}
