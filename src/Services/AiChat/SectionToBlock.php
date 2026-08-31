<?php

namespace VelaBuild\Core\Services\AiChat;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Read a written section's wording back out of its markup, and shape it into
 * the content a page-builder block takes.
 *
 * A design is built as written sections because that is what makes it look
 * like the design. What a block adds on top is the ability to restructure it —
 * add a card, take one out, reorder them — which is worth having wherever a
 * block can carry the section without losing anything. Deciding that before
 * the section exists is guesswork; reading the section that was actually built
 * is not.
 *
 * The fields were marked when the section was written, by the same pass that
 * lets the page builder show a plain form, so the wording, the pictures and
 * the links are already identified. What is left is the shape of each block,
 * and the honesty to refuse when the shape does not fit.
 */
class SectionToBlock
{
    /**
     * Which blocks can be filled from a section's markup, and what each needs.
     *
     * Only shapes whose mapping is unambiguous. A block whose meaning depends
     * on which paragraph is which — prices, columns of a comparison — is left
     * alone: a wrong guess there reads as a build that lost the design's
     * words, which is worse than a section that cannot be restructured.
     */
    public const CONVERTIBLE = ['hero', 'cta', 'testimonials', 'icon_box', 'accordion'];

    /**
     * @return array{type:string, content:array, background_image:?string, unused:array<int,string>}|array{error:string}
     */
    public function content(string $html, string $type): array
    {
        if (!in_array($type, self::CONVERTIBLE, true)) {
            return ['error' => 'A "' . $type . '" block cannot be filled from a written section — its meaning '
                . 'depends on which part of the wording is which, and a wrong guess loses the design\'s words. '
                . 'Only these can: ' . implode(', ', self::CONVERTIBLE) . '.'];
        }

        $fields = $this->fields($html);

        if ($fields === []) {
            return ['error' => 'Nothing in that section is marked as wording, a picture or a link, so there is '
                . 'nothing to fill a block with.'];
        }

        return match ($type) {
            'hero' => $this->hero($fields),
            'cta' => $this->cta($fields),
            'testimonials' => $this->testimonials($fields),
            'icon_box' => $this->iconBox($fields),
            'accordion' => $this->accordion($fields),
        };
    }

    /**
     * The marked pieces of the section, in the order they appear.
     *
     * @return array<int, array{kind:string, tag:string, text:string, href:?string, src:?string, heading:bool}>
     */
    public function fields(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $fields = [];

        foreach ((new DOMXPath($doc))->query('//*[@data-vela-field]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            $kinds = preg_split('/\s+/', trim($node->getAttribute('data-vela-field-kind'))) ?: [];

            $fields[] = [
                'kind' => $kinds[0] ?? 'text',
                'kinds' => $kinds,
                'tag' => $tag,
                'text' => trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? ''),
                'href' => $node->getAttribute('href') ?: null,
                'src' => $node->getAttribute('src') ?: null,
                'heading' => (bool) preg_match('/^h[1-6]$/', $tag),
            ];
        }

        return $fields;
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function hero(array $fields): array
    {
        $words = $this->words($fields);
        $links = $this->links($fields);

        if ($words === []) {
            return ['error' => 'A hero needs a heading, and that section has no wording outside its links.'];
        }

        $title = array_shift($words);
        $subtitle = $words ? array_shift($words) : '';

        $content = array_filter([
            'title' => $title['text'],
            'subtitle' => $subtitle ? $subtitle['text'] : null,
            'primary_button_text' => $links[0]['text'] ?? null,
            'primary_button_url' => $links[0]['href'] ?? null,
            'secondary_button_text' => $links[1]['text'] ?? null,
            'secondary_button_url' => $links[1]['href'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return [
            'type' => 'hero',
            'content' => $content,
            // A hero prints its words over a picture, so the section's first
            // one becomes the background rather than being dropped.
            'background_image' => $this->pictures($fields)[0]['src'] ?? null,
            'unused' => array_map(fn ($f) => $f['text'], $words)
                + array_map(fn ($f) => $f['text'], array_slice($links, 2)),
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function cta(array $fields): array
    {
        $words = $this->words($fields);
        $links = $this->links($fields);

        if ($words === []) {
            return ['error' => 'A call to action needs a heading, and that section has none.'];
        }

        $heading = array_shift($words);
        $description = $words ? array_shift($words) : null;
        $note = $words ? array_shift($words) : null;

        return [
            'type' => 'cta',
            'content' => array_filter([
                'heading' => $heading['text'],
                'description' => $description['text'] ?? null,
                'note' => $note['text'] ?? null,
                'primary_button_text' => $links[0]['text'] ?? null,
                'primary_button_url' => $links[0]['href'] ?? null,
                'secondary_button_text' => $links[1]['text'] ?? null,
                'secondary_button_url' => $links[1]['href'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'background_image' => null,
            'unused' => array_map(fn ($f) => $f['text'], $words),
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function testimonials(array $fields): array
    {
        $words = $this->words($fields);

        if (count($words) < 2) {
            return ['error' => 'A testimonial is a quotation and the name of whoever said it, and that section '
                . 'does not have both.'];
        }

        $items = [];

        // The quotation is the long piece and the name is the short one after
        // it, which is how a design lays a testimonial out.
        for ($i = 0; $i + 1 < count($words); $i += 2) {
            $items[] = array_filter([
                'quote' => $words[$i]['text'],
                'name' => $words[$i + 1]['text'],
            ]);
        }

        return [
            'type' => 'testimonials',
            'content' => ['items' => $items],
            'background_image' => null,
            'unused' => count($words) % 2 === 1 ? [end($words)['text']] : [],
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function iconBox(array $fields): array
    {
        ['pairs' => $items, 'dropped' => $dropped] = $this->headedPairs($fields);

        if ($items === []) {
            return ['error' => 'A row of boxes is a heading and a line of text, repeated, and that section is not '
                . 'laid out that way.'];
        }

        return [
            'type' => 'icon_box',
            'content' => ['items' => array_map(fn ($p) => array_filter([
                'title' => $p['title'],
                'description' => $p['body'],
            ]), $items)],
            'background_image' => null,
            'unused' => $dropped,
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function accordion(array $fields): array
    {
        ['pairs' => $items, 'dropped' => $dropped] = $this->headedPairs($fields);

        if ($items === []) {
            return ['error' => 'An accordion is a question and its answer, repeated, and that section is not laid '
                . 'out that way.'];
        }

        return [
            'type' => 'accordion',
            'content' => ['items' => array_map(fn ($p) => array_filter([
                'title' => $p['title'],
                'body' => $p['body'],
            ]), $items)],
            'background_image' => null,
            'unused' => $dropped,
        ];
    }

    private function headedPairs(array $fields): array
    {
        $words = $this->words($fields);
        $pairs = [];
        $open = null;

        foreach ($words as $word) {
            if ($word['heading'] || ($open === null && $pairs === [])) {
                if ($open !== null) {
                    $pairs[] = $open;
                }
                $open = ['title' => $word['text'], 'body' => ''];
                continue;
            }

            if ($open === null) {
                continue;
            }

            $open['body'] = trim($open['body'] . ' ' . $word['text']);
        }

        if ($open !== null) {
            $pairs[] = $open;
        }

        // A section's own heading — "Frequently Asked Questions" over the list
        // — has nothing under it, and taken as a pair it became a question in
        // the accordion with no answer. Anything with no body is not one of
        // the repeated things; it is the title of the section they sit in, and
        // the block has nowhere to put it, so it is handed back as wording
        // that would be lost rather than quietly dropped.
        $dropped = array_values(array_map(
            fn ($p) => $p['title'],
            array_filter($pairs, fn ($p) => $p['body'] === '')
        ));
        $pairs = array_values(array_filter($pairs, fn ($p) => $p['body'] !== ''));

        // One pair is not a repetition; that is a heading with a paragraph
        // under it, which is a written section and not a row of boxes.
        return count($pairs) > 1
            ? ['pairs' => $pairs, 'dropped' => $dropped]
            : ['pairs' => [], 'dropped' => []];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function words(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn ($f) => in_array('text', $f['kinds'], true)
                && !in_array('link', $f['kinds'], true)
                && $f['text'] !== ''
        ));
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function links(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn ($f) => in_array('link', $f['kinds'], true) && $f['text'] !== '' && $f['href']
        ));
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function pictures(array $fields): array
    {
        return array_values(array_filter($fields, fn ($f) => in_array('image', $f['kinds'], true) && $f['src']));
    }
}
