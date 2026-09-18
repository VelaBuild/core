<?php

namespace VelaBuild\Core\Services\Blocks;

use VelaBuild\Core\Models\Review;
use VelaBuild\Core\Services\DesignTokens;

/**
 * What the three review blocks share: the reviews themselves, their settings,
 * and the stars.
 *
 * The stars were Font Awesome icons and Bootstrap colour classes. No public
 * layout loads either, so on most sites a summary drew five blank gaps, and
 * where an icon font happened to be there anyway every star looked the same
 * whether it was earned or not. They are drawn here instead, as SVG.
 */
class Reviews
{
    public const LAYOUTS = ['row', 'card', 'large'];
    public const CARD_STYLES = ['bordered', 'soft', 'plain'];

    /**
     * How many published reviews there are at or above a rating, and their
     * average — counted by the database rather than by loading every row.
     *
     * @return array{count: int, average: float}
     */
    public static function totals(int $minRating): array
    {
        $row = Review::published()->where('rating', '>=', $minRating)
            ->selectRaw('COUNT(*) as reviews, AVG(rating) as average')
            ->first();

        return [
            'count'   => (int) ($row->reviews ?? 0),
            'average' => round((float) ($row->average ?? 0), 1),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Review> */
    public static function newest(int $minRating, int $limit)
    {
        return Review::published()->where('rating', '>=', $minRating)
            ->orderByDesc('review_date')
            ->take($limit)
            ->get();
    }

    private static function pick(array $raw, string $key, array $allowed, string $default): string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function whole(array $raw, string $key, int $default, int $min, int $max): int
    {
        $value = $raw[$key] ?? null;

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    private static function flag(array $raw, string $key, bool $default): bool
    {
        // Absent means the block was saved before the choice existed.
        return array_key_exists($key, $raw) ? filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN) : $default;
    }

    public static function summarySettings(array $raw): array
    {
        return [
            'min_rating'     => self::whole($raw, 'min_rating', 1, 1, 5),
            'layout'         => self::pick($raw, 'layout', self::LAYOUTS, 'row'),
            'text_alignment' => self::pick($raw, 'text_alignment', ['center', 'left', 'right'], 'center'),
            'background'     => trim((string) ($raw['background'] ?? '')),
            'show_count'     => self::flag($raw, 'show_count', true),
            'button_style'   => self::pick($raw, 'button_style', ['solid', 'pill', 'outline'], 'solid'),
        ];
    }

    public static function listSettings(array $raw, int $defaultCount, bool $withColumns): array
    {
        $s = [
            'min_rating'     => self::whole($raw, 'min_rating', 1, 1, 5),
            'max_count'      => self::whole($raw, 'max_count', $defaultCount, 1, 50),
            'card_style'     => self::pick($raw, 'card_style', self::CARD_STYLES, 'bordered'),
            'text_alignment' => self::pick($raw, 'text_alignment', ['left', 'center', 'right'], 'left'),
            'background'     => trim((string) ($raw['background'] ?? '')),
            'show_date'      => self::flag($raw, 'show_date', true),
            'show_source'    => self::flag($raw, 'show_source', false),
        ];
        if ($withColumns) {
            $s['columns'] = self::whole($raw, 'columns', 3, 1, 4);
        }

        return $s;
    }

    /**
     * A chosen background and an ink that reads on it, as the other blocks do.
     *
     * @return array{classes: string[], style: string}
     */
    public static function look(string $block, array $s): array
    {
        $classes = [$block, $block . '--align-' . $s['text_alignment']];
        foreach (['layout', 'card_style'] as $key) {
            if (isset($s[$key])) {
                $classes[] = $block . '--' . $s[$key];
            }
        }
        $style = 'text-align:' . $s['text_alignment'] . ';';

        if ($bg = DesignTokens::colour($s['background'])) {
            $classes[] = 'has-review-bg';
            $style .= '--review-bg:' . $bg . ';';
            if ($ink = DesignTokens::inkFor($s['background'])) {
                $style .= '--review-ink:' . $ink . ';';
            }
        }

        return ['classes' => $classes, 'style' => $style];
    }

    /**
     * Five stars for a rating, as SVG: filled, half filled, or empty.
     *
     * Half a star matters on the summary — 4.5 rounded to 5 is a claim the
     * site cannot back up.
     */
    public static function stars(float $rating, string $label = ''): string
    {
        static $drawn = 0;
        $drawn++;
        $path = 'M12 2.6l2.9 5.88 6.49.94-4.7 4.58 1.11 6.46L12 17.4l-5.8 3.06 1.11-6.46-4.7-4.58 6.49-.94z';
        $out = '<span class="review-stars" role="img" aria-label="' . e($label) . '">';

        for ($i = 1; $i <= 5; $i++) {
            $fill = max(0.0, min(1.0, $rating - ($i - 1)));
            // A star is half filled from a quarter to three quarters of one.
            $state = $fill >= 0.75 ? 'full' : ($fill >= 0.25 ? 'half' : 'empty');
            // Unique on the page: two gradients sharing an id is one gradient.
            $id = 'review-half-' . $drawn . '-' . $i;
            $out .= '<svg class="review-star review-star--' . $state . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
            if ($state === 'half') {
                $out .= '<defs><linearGradient id="' . $id . '"><stop offset="50%" stop-color="currentColor"></stop>'
                    . '<stop offset="50%" stop-color="currentColor" stop-opacity="0"></stop></linearGradient></defs>'
                    . '<path d="' . $path . '" fill="url(#' . $id . ')" stroke="currentColor" stroke-width="1.2"></path>';
            } else {
                $out .= '<path d="' . $path . '" fill="' . ($state === 'full' ? 'currentColor' : 'none')
                    . '" stroke="currentColor" stroke-width="1.2"></path>';
            }
            $out .= '</svg>';
        }

        return $out . '</span>';
    }

    /**
     * What the page editor needs to draw these blocks as they will be: how
     * many reviews each "lowest rating" leaves and what they average, and a
     * few real ones for the preview.
     *
     * @return array{byMin: array<int, array{count: int, average: float}>, samples: array<int, array<string, mixed>>}
     */
    public static function forEditor(): array
    {
        $rows = Review::published()
            ->selectRaw('rating, COUNT(*) as reviews')
            ->groupBy('rating')
            ->pluck('reviews', 'rating')
            ->all();

        $byMin = [];
        for ($min = 1; $min <= 5; $min++) {
            $count = 0;
            $sum = 0;
            foreach ($rows as $rating => $n) {
                if ((int) $rating >= $min) {
                    $count += (int) $n;
                    $sum += (int) $rating * (int) $n;
                }
            }
            $byMin[$min] = ['count' => $count, 'average' => $count ? round($sum / $count, 1) : 0.0];
        }

        $samples = self::newest(1, 6)->map(fn (Review $review) => [
            'author' => (string) $review->author,
            'rating' => (int) $review->rating,
            'text'   => mb_substr((string) $review->text, 0, 140),
            'date'   => $review->review_date?->translatedFormat('j M Y') ?? '',
            'source' => (string) $review->source,
        ])->all();

        return ['byMin' => $byMin, 'samples' => $samples];
    }

    /**
     * The summary as schema.org data, so a search engine can show the stars
     * it earns beside the site's own result.
     */
    public static function aggregateRatingJsonLd(array $totals, int $minRating): ?string
    {
        if ($totals['count'] < 1 || $totals['average'] <= 0) {
            return null;
        }

        return (string) json_encode([
            '@context'    => 'https://schema.org',
            '@type'       => 'AggregateRating',
            'ratingValue' => $totals['average'],
            'reviewCount' => $totals['count'],
            'bestRating'  => 5,
            'worstRating' => max(1, $minRating),
            'itemReviewed' => [
                '@type' => 'Organization',
                'name'  => (string) (vela_config('site_name') ?: config('app.name', 'Website')),
                'url'   => url('/'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
