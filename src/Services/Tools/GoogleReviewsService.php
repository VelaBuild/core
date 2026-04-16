<?php

namespace VelaBuild\Core\Services\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\Review;
use VelaBuild\Core\Services\ToolSettingsService;

class GoogleReviewsService
{
    private const PLACES_API_URL = 'https://maps.googleapis.com/maps/api/place/details/json';

    public function __construct(
        private ToolSettingsService $settings
    ) {}

    /**
     * Fetch reviews from Google Places API and sync to local DB.
     * Returns count of new/updated reviews.
     */
    public function syncReviews(): array
    {
        $apiKey = $this->settings->get('google_places_api_key');
        $placeId = $this->settings->get('google_place_id');

        if (!$apiKey || !$placeId) {
            return ['synced' => 0, 'error' => 'Missing API key or Place ID'];
        }

        $response = Http::timeout(30)->get(self::PLACES_API_URL, [
            'place_id' => $placeId,
            'fields' => 'reviews',
            'key' => $apiKey,
        ]);

        if (!$response->successful()) {
            Log::error('Google Places API failed', ['status' => $response->status()]);
            return ['synced' => 0, 'error' => 'API request failed'];
        }

        $data = $response->json();
        $reviews = $data['result']['reviews'] ?? [];
        $synced = 0;

        foreach ($reviews as $review) {
            $externalId = 'google_' . md5($review['author_name'] . ($review['time'] ?? ''));

            Review::firstOrCreate(
                ['external_id' => $externalId],
                [
                    'source' => 'google',
                    'place_id' => $placeId,
                    'author' => $review['author_name'] ?? 'Anonymous',
                    'rating' => (int) ($review['rating'] ?? 5),
                    'text' => $review['text'] ?? null,
                    'review_date' => isset($review['time']) ? date('Y-m-d H:i:s', $review['time']) : now(),
                    'synced_at' => now(),
                    'published' => true,
                ]
            );
            $synced++;
        }

        Log::info('Google Reviews synced', ['count' => $synced]);
        return ['synced' => $synced, 'error' => null];
    }

    public function isConfigured(): bool
    {
        return $this->settings->hasKey('google_places_api_key') && $this->settings->hasKey('google_place_id');
    }
}
