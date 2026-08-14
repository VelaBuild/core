<?php

use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;

if (!function_exists('vela_wire_imported_form')) {
    /**
     * Make a copied form actually submit somewhere.
     *
     * A section imported from another site brings its form's markup and none
     * of its plumbing: the importer strips the action on purpose, because the
     * alternative is posting a visitor's details to the site it was copied
     * from. Left like that the form is a picture — the button does nothing.
     *
     * So the wiring is added when the page renders, not when it is stored:
     * the address of this site's own endpoint, a CSRF token that has to be
     * fresh, the honeypot, and the block id. The stored markup stays portable
     * and free of anything request-specific.
     */
    function vela_wire_imported_form(string $html, ?Page $page = null, ?PageBlock $block = null): string
    {
        if ($page === null || $html === '' || !str_contains($html, '<form')) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="vela-form-root">' . $html . '</div>',
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $forms = $doc->getElementsByTagName('form');
        if ($forms->length === 0) {
            return $html;
        }

        $action = route('vela.public.page-form.submit', $page);
        $index = 0;

        foreach (iterator_to_array($forms) as $form) {
            if (!$form instanceof DOMElement) {
                continue;
            }

            // A form that already posts somewhere is left alone — that is
            // either this site's own block or a deliberate choice.
            if (trim($form->getAttribute('action')) !== '') {
                continue;
            }

            $form->setAttribute('action', $action);
            $form->setAttribute('method', 'POST');

            $hidden = [
                // Null outside a request lifecycle (a console render); the
                // cast below turns that into "skip" rather than a deprecation.
                '_token'   => csrf_token(),
                'block_id' => (string) ($block->id ?? ''),
            ];
            foreach ($hidden as $name => $value) {
                $value = (string) $value;
                if ($value === '') {
                    continue;
                }
                $input = $doc->createElement('input');
                $input->setAttribute('type', 'hidden');
                $input->setAttribute('name', $name);
                $input->setAttribute('value', $value);
                $form->insertBefore($input, $form->firstChild);
            }

            // Same honeypot the built-in contact block uses: a field a person
            // never sees and a robot fills in.
            $honeypot = $doc->createElement('input');
            $honeypot->setAttribute('type', 'text');
            $honeypot->setAttribute('name', 'website_url');
            $honeypot->setAttribute('tabindex', '-1');
            $honeypot->setAttribute('autocomplete', 'off');
            $honeypot->setAttribute('aria-hidden', 'true');
            $honeypot->setAttribute('style', 'position:absolute;left:-9999px;width:1px;height:1px;opacity:0');
            $form->insertBefore($honeypot, $form->firstChild);

            vela_name_unnamed_form_controls($form, $index++);
        }

        $root = $doc->getElementById('vela-form-root');

        return $root ? vela_inner_html($root) : $html;
    }
}

if (!function_exists('vela_name_unnamed_form_controls')) {
    /**
     * Give every control a name.
     *
     * Copied markup often drives its fields from JavaScript, so the inputs
     * carry an id or a placeholder and no name at all — and a control without
     * a name is not submitted. The label is used where there is one, so what
     * lands in the admin reads like the form rather than "field_3".
     */
    function vela_name_unnamed_form_controls(DOMElement $form, int $formIndex = 0): void
    {
        $used = [];
        $counter = 0;

        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach (iterator_to_array($form->getElementsByTagName($tag)) as $control) {
                if (!$control instanceof DOMElement) {
                    continue;
                }

                $type = strtolower($control->getAttribute('type'));
                if ($tag === 'input' && in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                    continue;
                }

                $counter++;
                $name = trim($control->getAttribute('name'));
                if ($name === '') {
                    $name = vela_form_control_name($form, $control) ?: 'field_' . $counter;
                }

                // Two "Email" labels in one form would otherwise overwrite
                // each other in the stored submission.
                $base = $name;
                $suffix = 2;
                while (isset($used[$name])) {
                    $name = $base . '_' . $suffix++;
                }
                $used[$name] = true;

                $control->setAttribute('name', $name);
            }
        }
    }
}

if (!function_exists('vela_form_control_name')) {
    /** A field name derived from the control's label, id or placeholder. */
    function vela_form_control_name(DOMElement $form, DOMElement $control): string
    {
        $candidates = [];

        $id = trim($control->getAttribute('id'));
        if ($id !== '') {
            foreach ($form->getElementsByTagName('label') as $label) {
                if ($label instanceof DOMElement && $label->getAttribute('for') === $id) {
                    $candidates[] = $label->textContent;
                    break;
                }
            }
            $candidates[] = $id;
        }

        $candidates[] = $control->getAttribute('placeholder');
        $candidates[] = $control->getAttribute('aria-label');

        foreach ($candidates as $candidate) {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $candidate), '_'));
            if ($slug !== '' && strlen($slug) <= 40) {
                return $slug;
            }
        }

        return '';
    }
}

if (!function_exists('vela_inner_html')) {
    /** The markup inside an element, without the element itself. */
    function vela_inner_html(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return $html;
    }
}
