<?php
namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

abstract class BaseTool
{
    abstract public function execute(array $parameters, ?AiActionLog $actionLog = null): array;

    public function undo(AiActionLog $actionLog): void
    {
        throw new \RuntimeException('Undo not supported for this tool.');
    }

    /**
     * Reject block content carrying keys the block's view never reads.
     *
     * A block's registered defaults.content enumerates every key its Blade view
     * looks up, so an unknown key means an invented shape whose value is dropped
     * at render time — the block then renders empty while the tool reports
     * success. Returns an error array to hand back to the AI, or null if valid.
     */
    protected function validateBlockContent(string $type, $content): ?array
    {
        return $this->validateBlockShape($type, $content, 'content');
    }

    /**
     * Same check for a block's `settings` payload.
     */
    protected function validateBlockSettings(string $type, $settings): ?array
    {
        return $this->validateBlockShape($type, $settings, 'settings');
    }

    /**
     * Strip stray escaping from link fields.
     *
     * Models sometimes emit "\/contact-us" for "/contact-us"; the extra
     * backslash survives into the href and the link silently 404s. Only URL-ish
     * keys are touched, so code/html payloads that legitimately contain
     * backslashes are left alone.
     */
    protected function normalizeBlockUrls($content)
    {
        if (!is_array($content)) {
            return $content;
        }

        foreach ($content as $key => $value) {
            if (is_array($value)) {
                $content[$key] = $this->normalizeBlockUrls($value);
                continue;
            }
            if (is_string($value) && preg_match('/(^|_)(url|link|href|image|src)$/i', (string) $key)) {
                $content[$key] = str_replace('\\/', '/', $value);
            }
        }

        return $content;
    }

    private function validateBlockShape(string $type, $content, string $section): ?array
    {
        if (!is_array($content) || $content === []) {
            return null;
        }

        $definition = app(\VelaBuild\Core\Vela::class)->blocks()->all()[$type] ?? null;
        $known = $definition['defaults'][$section] ?? null;
        // No enumerated shape (e.g. posts_grid, which is driven by settings
        // alone) — there is nothing to validate against.
        if (!is_array($known) || $known === []) {
            return null;
        }

        $unknown = array_values(array_diff(array_keys($content), array_keys($known)));
        if ($unknown === []) {
            return null;
        }

        return [
            'error' => "Block type '{$type}' has no {$section} key(s): " . implode(', ', $unknown)
                . ". Values under unsupported keys are dropped when the page renders, so the {$section} has no effect. "
                . "Resend using only the keys in valid_{$section}_keys. "
                . 'A block background image is not a settings key — pass background_image (a URL) as its own parameter.',
            "valid_{$section}_keys" => array_keys($known),
            'unknown_keys'          => $unknown,
        ];
    }
}
