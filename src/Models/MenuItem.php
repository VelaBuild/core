<?php

namespace VelaBuild\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class MenuItem extends Model
{
    public $table = 'vela_menu_items';

    public const TYPE_PAGE             = 'page';
    public const TYPE_CONTENT          = 'content';
    public const TYPE_CATEGORY         = 'category';
    public const TYPE_URL              = 'url';
    public const TYPE_ROUTE            = 'route';
    public const TYPE_HOME             = 'home';
    public const TYPE_POSTS_INDEX      = 'posts_index';
    public const TYPE_CATEGORIES_INDEX = 'categories_index';

    protected $fillable = [
        'menu_id',
        'order_column',
        'type',
        'ref_type',
        'ref_id',
        'label',
        'url',
        'route_name',
        'target',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * Resolve the displayed label, falling back to the referenced model's title.
     */
    public function resolveLabel(): string
    {
        $locale = app()->getLocale();
        $translating = $locale !== config('vela.primary_language', 'en');

        if (! empty($this->label)) {
            if ($translating) {
                $translated = Translation::where('model_type', 'MenuItem')
                    ->where('model_key', $this->id . '_label')
                    ->where('lang_code', $locale)
                    ->value('translation');
                if ($translated) {
                    return $translated;
                }
            }

            return $this->label;
        }

        return match ($this->type) {
            self::TYPE_HOME             => __('vela::public.home'),
            self::TYPE_POSTS_INDEX      => __('vela::public.articles'),
            self::TYPE_CATEGORIES_INDEX => __('vela::public.topics'),
            self::TYPE_PAGE             => ($translating ? Page::where('parent_id', $this->ref_id)->where('locale', $locale)->value('title') : null)
                                            ?? optional(Page::find($this->ref_id))->title ?? '',
            self::TYPE_CONTENT          => optional(Content::find($this->ref_id))->title ?? '',
            self::TYPE_CATEGORY         => optional(Category::find($this->ref_id))->translated_name ?? '',
            default                     => $this->url ?: '',
        };
    }

    /**
     * Resolve to a fully-qualified URL for this menu item.
     * Returns '#' for unresolvable items so the menu still renders.
     */
    public function resolveUrl(): string
    {
        $locale = app()->getLocale();

        try {
            switch ($this->type) {
                case self::TYPE_HOME:
                    return route('vela.public.home');

                case self::TYPE_POSTS_INDEX:
                    return route('vela.public.posts.index');

                case self::TYPE_CATEGORIES_INDEX:
                    return route('vela.public.categories.index');

                case self::TYPE_PAGE:
                    $page = Page::find($this->ref_id);
                    if (! $page) return '#';
                    return LaravelLocalization::getLocalizedURL($locale, '/' . $page->slug);

                case self::TYPE_CONTENT:
                    $content = Content::find($this->ref_id);
                    if (! $content) return '#';
                    return route('vela.public.posts.show', $content->slug);

                case self::TYPE_CATEGORY:
                    $category = Category::find($this->ref_id);
                    if (! $category) return '#';
                    return route('vela.public.categories.show', $category->slug);

                case self::TYPE_ROUTE:
                    return $this->route_name ? route($this->route_name) : '#';

                case self::TYPE_URL:
                default:
                    return $this->url ?: '#';
            }
        } catch (\Throwable $e) {
            return '#';
        }
    }

    /**
     * True when the resolveUrl() target matches the current request — used
     * by the renderer to add an `is-active` class.
     */
    public function isActive(): bool
    {
        $url = $this->resolveUrl();
        if ($url === '#' || $url === '') {
            return false;
        }
        $current = rtrim(request()->url(), '/');
        $target  = rtrim($url, '/');
        return $current === $target;
    }
}
