<?php

namespace VelaBuild\Core\Services\Blocks;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Translation;

/**
 * Which posts a posts grid block shows and what each card says.
 *
 * One place for it because two things draw the block: the page itself, and
 * the live preview in its editor dialog, which has to show the same posts or
 * it is a preview of something else.
 */
class PostsGrid
{
    public const LAYOUTS = ['grid', 'featured', 'list', 'slider'];
    public const STYLES = ['bordered', 'soft', 'plain'];
    public const RATIOS = ['auto', '16x9', '4x3', '1x1'];
    public const ORDERS = ['newest', 'oldest', 'title_asc', 'title_desc'];

    /**
     * Settings as the block stores them, made safe to use: every key present,
     * every value one the view knows.
     */
    public static function settings(array $raw): array
    {
        $pick = fn (string $key, array $allowed) => in_array($raw[$key] ?? null, $allowed, true) ? $raw[$key] : $allowed[0];
        $flag = function (string $key, bool $default) use ($raw): bool {
            if (!array_key_exists($key, $raw) || $raw[$key] === null || $raw[$key] === '') {
                return $default;
            }

            return filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        };
        $ids = fn ($value) => array_values(array_unique(array_filter(array_map('intval', (array) $value), fn ($id) => $id > 0)));

        // category_id is the single filter the block had first; a block saved
        // with it, or an AI told about it, still filters by it.
        $categoryIds = $ids($raw['category_ids'] ?? []);
        if ($categoryIds === [] && !empty($raw['category_id'])) {
            $categoryIds = $ids([$raw['category_id']]);
        }

        return [
            'source'            => $pick('source', ['latest', 'chosen']),
            'post_ids'          => $ids($raw['post_ids'] ?? []),
            'category_ids'      => $categoryIds,
            'order_by'          => $pick('order_by', self::ORDERS),
            'max_count'         => max(1, min(50, (int) ($raw['max_count'] ?? 12) ?: 12)),
            'skip'              => max(0, min(50, (int) ($raw['skip'] ?? 0))),
            'columns'           => max(1, min(6, (int) ($raw['columns'] ?? 3) ?: 3)),
            'layout'            => $pick('layout', self::LAYOUTS),
            'card_style'        => $pick('card_style', self::STYLES),
            'ratio'             => $pick('ratio', self::RATIOS),
            'show_image'        => $flag('show_image', true),
            'show_excerpt'      => $flag('show_excerpt', true),
            'show_date'         => $flag('show_date', true),
            'show_category'     => $flag('show_category', false),
            'show_author'       => $flag('show_author', false),
            'show_reading_time' => $flag('show_reading_time', false),
            'autoplay'          => $flag('autoplay', false),
            'interval'          => max(2000, (int) ($raw['interval'] ?? 5000)),
            'button_text'       => trim((string) ($raw['button_text'] ?? '')),
            'button_url'        => trim((string) ($raw['button_url'] ?? '')),
        ];
    }

    /** The posts for normalised settings, with what their cards read loaded up front. */
    public static function posts(array $s): Collection
    {
        $query = Content::query()
            ->where('status', 'published')
            ->with(['media', 'categories', 'author']);

        if ($s['source'] === 'chosen' && $s['post_ids'] !== []) {
            $position = array_flip($s['post_ids']);

            return $query->whereIn('id', $s['post_ids'])->get()
                ->sortBy(fn ($post) => $position[$post->id] ?? PHP_INT_MAX)->values();
        }

        if ($s['category_ids'] !== []) {
            $query->whereHas('categories', fn ($q) => $q->whereIn('vela_categories.id', $s['category_ids']));
        }

        match ($s['order_by']) {
            'oldest'     => $query->orderByRaw('COALESCE(published_at, created_at) ASC'),
            'title_asc'  => $query->orderBy('title', 'asc'),
            'title_desc' => $query->orderBy('title', 'desc'),
            default      => $query->orderByRaw('COALESCE(published_at, created_at) DESC'),
        };

        return $query->skip($s['skip'])->take($s['max_count'])->get();
    }

    /**
     * What each card shows, with the translations for all of them read in one
     * query rather than two per card.
     */
    public static function cards(Collection $posts): array
    {
        if ($posts->isEmpty()) {
            return [];
        }

        $locale = app()->getLocale();
        $keys = [];
        foreach ($posts as $post) {
            $keys[] = $post->id . '_title';
            $keys[] = $post->id . '_description';
        }
        $categoryIds = $posts->flatMap(fn ($post) => $post->categories->pluck('id'))->unique();

        $translated = Translation::query()
            ->where('lang_code', $locale)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('model_type', 'Content')->whereIn('model_key', $keys))
                ->orWhere(fn ($w) => $w->where('model_type', 'Category')->whereIn('model_key', $categoryIds->map(fn ($id) => $id . '_name')->all())))
            ->get()
            ->filter(fn ($t) => (string) $t->translation !== '')
            ->mapWithKeys(fn ($t) => [$t->model_type . ':' . $t->model_key => $t->translation]);

        return $posts->map(function (Content $post) use ($translated) {
            $category = $post->categories->sortBy('order_by')->first();
            $description = (string) ($translated['Content:' . $post->id . '_description'] ?? $post->description);

            return [
                'id'       => $post->id,
                'url'      => url('/posts/' . $post->slug),
                'title'    => (string) ($translated['Content:' . $post->id . '_title'] ?? $post->title),
                'excerpt'  => trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5)),
                'date'     => $post->published_at ?? $post->created_at,
                'image'    => $post->main_image,
                'category' => $category ? (string) ($translated['Category:' . $category->id . '_name'] ?? $category->name) : null,
                'author'   => $post->author?->name,
                'minutes'  => self::readingMinutes((string) $post->content),
            ];
        })->all();
    }

    /**
     * Minutes to read an article, from its text.
     *
     * Counted in characters rather than words: Thai and Chinese are written
     * without spaces, so a word count calls a long article a one-word read.
     * About 1,000 characters a minute sits close to an English reader's 200
     * words and is not far off for the others.
     */
    public static function readingMinutes(string $content): int
    {
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['blocks']) && is_array($data['blocks'])) {
            $text = collect($data['blocks'])->map(function ($block) {
                $d = $block['data'] ?? [];

                return implode(' ', array_filter([
                    is_string($d['text'] ?? null) ? $d['text'] : null,
                    is_array($d['items'] ?? null) ? implode(' ', array_map(fn ($i) => is_string($i) ? $i : (string) ($i['content'] ?? ''), $d['items'])) : null,
                ]));
            })->implode(' ');
        } else {
            $text = $content;
        }

        $chars = mb_strlen((string) preg_replace('/\s+/u', '', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));

        return max(1, (int) ceil($chars / 1000));
    }

    /** A card's excerpt, cut at a word where the language has them. */
    public static function excerpt(string $text, int $limit): string
    {
        return Str::limit($text, $limit);
    }
}
