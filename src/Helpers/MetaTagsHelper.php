<?php

namespace VelaBuild\Core\Helpers;

use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Category;

class MetaTagsHelper
{
    /**
     * Generate meta tags for content
     */
    public static function forContent(Content $content): array
    {
        $appName = app(\VelaBuild\Core\Services\SiteContext::class)->getName();
        $title = $content->translated_title . ' - ' . $appName;
        $description = $content->translated_description
            ? \Str::limit($content->translated_description, 160)
            : 'Explore content on ' . $appName . '.';
        $keywords = $content->keyword
            ? $content->keyword
            : $appName;
        $image = $content->main_image ? $content->main_image->getUrl() : asset('images/hero-image.jpg');
        $url = url('/posts/' . $content->slug);

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'og_type' => 'article',
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $image,
            'og_image_alt' => $content->translated_title,
            'og_url' => $url,
            'twitter_title' => $title,
            'twitter_description' => $description,
            'twitter_image' => $image,
            'twitter_image_alt' => $content->translated_title,
            'canonical_url' => $url,
            'article_published_time' => $content->published_at
                ? $content->published_at->toISOString()
                : $content->created_at->toISOString(),
            'article_modified_time' => $content->updated_at->toISOString(),
            'article_author' => $appName,
            'article_section' => $content->categories->first()->translated_name ?? null,
            'article_tags' => $content->categories->pluck('translated_name')->implode(', '),
        ];
    }

    /**
     * Generate meta tags for category
     */
    public static function forCategory(Category $category): array
    {
        $appName = app(\VelaBuild\Core\Services\SiteContext::class)->getName();
        $title = $category->translated_name . ' - ' . $appName;
        $description = 'Browse ' . $category->translated_name . ' articles.';
        $keywords = $category->translated_name;
        $image = $category->main_image ? $category->main_image->getUrl() : asset('images/hero-image.jpg');
        $url = url('/categories/' . \Str::slug($category->name));

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'og_type' => 'website',
            'og_title' => $title,
            'og_description' => $description,
            'og_image' => $image,
            'og_image_alt' => $category->translated_name . ' Articles',
            'og_url' => $url,
            'twitter_title' => $title,
            'twitter_description' => $description,
            'twitter_image' => $image,
            'twitter_image_alt' => $category->translated_name . ' Articles',
            'canonical_url' => $url,
        ];
    }

    /**
     * Generate meta tags for home page
     */
    public static function forHome(): array
    {
        $appName = app(\VelaBuild\Core\Services\SiteContext::class)->getName();

        return [
            'title' => $appName,
            'description' => 'Welcome to ' . $appName . '.',
            'keywords' => $appName,
            'og_type' => 'website',
            'og_title' => $appName,
            'og_description' => 'Welcome to ' . $appName . '.',
            'og_image' => asset('images/hero-image.jpg'),
            'og_image_alt' => $appName,
            'og_url' => url('/'),
            'twitter_title' => $appName,
            'twitter_description' => 'Welcome to ' . $appName . '.',
            'twitter_image' => asset('images/hero-image.jpg'),
            'twitter_image_alt' => $appName,
            'canonical_url' => url('/'),
        ];
    }

    /**
     * Generate meta tags for articles index
     */
    public static function forArticlesIndex(): array
    {
        $appName = app(\VelaBuild\Core\Services\SiteContext::class)->getName();
        $title = 'All Articles - ' . $appName;

        return [
            'title' => $title,
            'description' => 'Browse all articles on ' . $appName . '.',
            'keywords' => 'articles, ' . $appName,
            'og_type' => 'website',
            'og_title' => $title,
            'og_description' => 'Browse all articles on ' . $appName . '.',
            'og_image' => asset('images/hero-image.jpg'),
            'og_image_alt' => $title,
            'og_url' => url('/posts'),
            'twitter_title' => $title,
            'twitter_description' => 'Browse all articles on ' . $appName . '.',
            'twitter_image' => asset('images/hero-image.jpg'),
            'twitter_image_alt' => $title,
            'canonical_url' => url('/posts'),
        ];
    }

    /**
     * Generate meta tags for categories index
     */
    public static function forCategoriesIndex(): array
    {
        $appName = app(\VelaBuild\Core\Services\SiteContext::class)->getName();
        $title = 'Topics - ' . $appName;

        return [
            'title' => $title,
            'description' => 'Explore topics on ' . $appName . '.',
            'keywords' => 'topics, categories, ' . $appName,
            'og_type' => 'website',
            'og_title' => $title,
            'og_description' => 'Explore topics on ' . $appName . '.',
            'og_image' => asset('images/hero-image.jpg'),
            'og_image_alt' => $title,
            'og_url' => url('/categories'),
            'twitter_title' => $title,
            'twitter_description' => 'Explore topics on ' . $appName . '.',
            'twitter_image' => asset('images/hero-image.jpg'),
            'twitter_image_alt' => $title,
            'canonical_url' => url('/categories'),
        ];
    }
}
