<?php

namespace VelaBuild\Core\Jobs;

use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\SiteContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateContentImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    protected $content;

    /**
     * Create a new job instance.
     */
    public function __construct(Content $content)
    {
        $this->content = $content;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $aiManager = app(AiProviderManager::class);
        $imageService = $aiManager->resolveImageProvider();

        try {
            // Refresh content from database to get latest state
            $this->content->refresh();

            // Check if content needs processing
            $needsMainImage = !$this->content->main_image;
            $contentBody = $this->content->content ?? '';
            $hasUnprocessedTags = strpos($contentBody, '[IMAGE') !== false;

            Log::info('Starting content image generation', [
                'content_id' => $this->content->id,
                'content_title' => $this->content->title,
                'needs_main_image' => $needsMainImage,
                'has_unprocessed_tags' => $hasUnprocessedTags
            ]);

            if (!$needsMainImage && !$hasUnprocessedTags) {
                Log::info('Content already fully processed', [
                    'content_id' => $this->content->id,
                    'has_main_image' => !$needsMainImage,
                    'has_unprocessed_tags' => $hasUnprocessedTags
                ]);
                return;
            }

            $imagesProcessed = 0;

            // PHASE 1: Generate main image if content doesn't have one
            if ($needsMainImage) {
                Log::info('Phase 1: Generating main image', [
                    'content_id' => $this->content->id
                ]);

                if ($this->generateMainImage($imageService)) {
                    $imagesProcessed++;
                }

                // Refresh content to get updated main image status
                $this->content->refresh();
            }

            // PHASE 2: Process content images one by one, updating content immediately
            Log::info('Phase 2: Processing content images', [
                'content_id' => $this->content->id,
                'has_unprocessed_tags' => $hasUnprocessedTags
            ]);

            while ($hasUnprocessedTags) {
                // Parse current content for image tags
                $imageTags = $this->parseImageTags($this->content->content);

                Log::info('Found image tags to process', [
                    'content_id' => $this->content->id,
                    'tags_count' => count($imageTags),
                    'tags' => array_map(function ($tag) {
                        return $tag['topic'];
                    }, $imageTags)
                ]);

                if (empty($imageTags)) {
                    Log::info('No more image tags found, breaking loop', [
                        'content_id' => $this->content->id
                    ]);
                    break; // No more images to process
                }

                // Process the first image tag
                $imageTag = $imageTags[0];

                try {
                    Log::info('Processing image tag', [
                        'content_id' => $this->content->id,
                        'topic' => $imageTag['topic'],
                        'alt' => $imageTag['alt']
                    ]);

                    // Generate image
                    $imageData = $imageService->generateImage(
                        $imageTag['topic'],
                        ['aspect_ratio' => '1:1', 'size' => '1024x1024', 'quality' => 'high']
                    );

                    if (!$imageData || !isset($imageData['data'][0]['b64_json'])) {
                        Log::error('Failed to generate content image', [
                            'content_id' => $this->content->id,
                            'topic' => $imageTag['topic']
                        ]);
                        // Remove the problematic tag to prevent infinite loop
                        $this->removeImageTag($imageTag);
                        continue;
                    }

                    // Save the image
                    $filename = $this->generateImageFilename($this->content, $imagesProcessed, $imageTag['alt']);
                    $savedPath = $imageService->saveBase64Image($imageData['data'][0]['b64_json'], $filename);

                    if (!$savedPath) {
                        Log::error('Failed to save content image', [
                            'content_id' => $this->content->id,
                            'filename' => $filename
                        ]);
                        // Remove the problematic tag to prevent infinite loop
                        $this->removeImageTag($imageTag);
                        continue;
                    }

                    // Attach to content_images collection
                    $fullPath = Storage::disk('public')->path($savedPath);
                    $media = $this->content->addMedia($fullPath)
                        ->toMediaCollection('content_images');

                    // IMMEDIATELY update content to replace this specific image tag
                    $this->replaceImageTagWithImage($imageTag, $media->getUrl(), $imageTag['alt']);

                    $imagesProcessed++;

                    Log::info('Successfully processed image and updated content', [
                        'content_id' => $this->content->id,
                        'filename' => $filename,
                        'alt' => $imageTag['alt'],
                        'images_processed' => $imagesProcessed
                    ]);

                } catch (\Exception $e) {
                    Log::error('Error generating content image', [
                        'content_id' => $this->content->id,
                        'topic' => $imageTag['topic'],
                        'error' => $e->getMessage()
                    ]);
                    // Remove the problematic tag to prevent infinite loop
                    $this->removeImageTag($imageTag);
                }

                // Refresh content to check for remaining tags
                $this->content->refresh();
                $hasUnprocessedTags = strpos($this->content->content, '[IMAGE') !== false;
            }

            Log::info('Completed content image generation', [
                'content_id' => $this->content->id,
                'images_processed' => $imagesProcessed,
                'main_image_generated' => $needsMainImage,
                'content_images_processed' => $imagesProcessed - ($needsMainImage ? 1 : 0)
            ]);

        } catch (\Exception $e) {
            Log::error('Content image generation failed', [
                'content_id' => $this->content->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Parse image tags from content (both EditorJS format and raw [IMAGE] tags)
     */
    private function parseImageTags(string $content): array
    {
        $imageTags = [];

        // Try to parse as EditorJS JSON first
        $contentData = json_decode($content, true);
        if ($contentData && isset($contentData['blocks'])) {
            foreach ($contentData['blocks'] as $block) {
                if ($block['type'] === 'paragraph' && isset($block['data']['text'])) {
                    // Check for [IMAGE] tags in paragraph text
                    $pattern = '/\[IMAGE\s+topic="([^"]+)"\s+alt="([^"]+)"\]/i';
                    if (preg_match_all($pattern, $block['data']['text'], $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $imageTags[] = [
                                'topic' => $match[1],
                                'alt' => $match[2],
                                'full_tag' => $match[0],
                                'block_id' => $block['id']
                            ];

                            Log::info('Found image tag in content', [
                                'content_id' => $this->content->id,
                                'full_tag' => $match[0],
                                'topic' => $match[1],
                                'alt' => $match[2]
                            ]);
                        }
                    }
                }
            }
        } else {
            // Fallback: parse raw [IMAGE] tags from plain text
            $pattern = '/\[IMAGE\s+topic="([^"]+)"\s+alt="([^"]+)"\]/i';
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $imageTags[] = [
                        'topic' => $match[1],
                        'alt' => $match[2],
                        'full_tag' => $match[0]
                    ];
                }
            }
        }

        return $imageTags;
    }

    /**
     * Generate main image based on content title and description
     */
    private function generateMainImage($imageService): bool
    {
        try {
            // Create a prompt based on title and description
            $prompt = $this->generateMainImagePrompt($this->content->title, $this->content->description);

            // Generate main image
            $imageData = $imageService->generateImage(
                $prompt,
                ['aspect_ratio' => '1:1', 'size' => '1024x1024', 'quality' => 'high']
            );

            if (!$imageData || !isset($imageData['data'][0]['b64_json'])) {
                Log::error('Failed to generate main image', [
                    'content_id' => $this->content->id,
                    'title' => $this->content->title
                ]);
                return false;
            }

            // Save the main image
            $filename = $this->generateMainImageFilename($this->content);
            $savedPath = $imageService->saveBase64Image($imageData['data'][0]['b64_json'], $filename);

            if (!$savedPath) {
                Log::error('Failed to save main image', [
                    'content_id' => $this->content->id,
                    'filename' => $filename
                ]);
                return false;
            }

            // Attach to main_image collection
            $fullPath = Storage::disk('public')->path($savedPath);
            $this->content->addMedia($fullPath)
                ->toMediaCollection('main_image');

            Log::info('Successfully generated and saved main image', [
                'content_id' => $this->content->id,
                'filename' => $filename,
                'title' => $this->content->title
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error generating main image', [
                'content_id' => $this->content->id,
                'title' => $this->content->title,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generate prompt for main image based on title and description
     */
    private function generateMainImagePrompt(string $title, ?string $description): string
    {
        $description = $description ? strip_tags($description) : '';
        $description = substr($description, 0, 200); // Limit description length

        $descriptionText = $description ? "Description: {$description}. " : '';

        $siteContext = app(SiteContext::class);
        $siteDesc = $siteContext->getDescription();

        return "A professional, high-quality image representing: {$title}. " .
               $descriptionText .
               "Style: Modern, clean, high-quality, suitable for {$siteDesc}. " .
               "High quality, commercial use style. Do NOT add text onto the image.";
    }

    /**
     * Generate filename for main image
     */
    private function generateMainImageFilename(Content $content): string
    {
        $slug = \Illuminate\Support\Str::slug($content->title);
        $timestamp = now()->format('Y-m-d-H-i-s');

        return "main-image-{$content->id}-{$slug}-{$timestamp}.png";
    }

    /**
     * Generate filename for content image
     */
    private function generateImageFilename(Content $content, int $index, string $alt): string
    {
        $slug = \Illuminate\Support\Str::slug($content->title);
        $altSlug = \Illuminate\Support\Str::slug($alt);
        $timestamp = now()->format('Y-m-d-H-i-s');

        return "content-{$content->id}-{$slug}-{$index}-{$altSlug}-{$timestamp}.png";
    }

    /**
     * Update content to replace image tags with actual image references
     */
    private function updateContentWithImages(array $generatedImages): void
    {
        $content = $this->content->content;

        // Parse content JSON
        $contentData = json_decode($content, true);
        if (!$contentData || !isset($contentData['blocks'])) {
            return;
        }

        $imageIndex = 0;

        // Process each block
        foreach ($contentData['blocks'] as &$block) {
            if ($block['type'] === 'paragraph' && isset($block['data']['text'])) {
                $text = $block['data']['text'];

                // Check if this paragraph contains an image tag
                if (preg_match('/\[IMAGE\s+topic="[^"]+"\s+alt="[^"]+"\]/i', $text)) {
                    if ($imageIndex < count($generatedImages)) {
                        $image = $generatedImages[$imageIndex];

                        // Media is already attached, just get the URL

                        // Replace image tag with image block
                        $block = [
                            'id' => 'image-' . $block['id'],
                            'type' => 'image',
                            'data' => [
                                'file' => [
                                    'url' => $image['media_url']
                                ],
                                'caption' => $image['alt'],
                                'withBorder' => false,
                                'withBackground' => false,
                                'stretched' => false
                            ]
                        ];

                        $imageIndex++;
                    }
                }
            }
        }

        // Update the content
        $this->content->update([
            'content' => json_encode($contentData)
        ]);
    }

    /**
     * Replace a specific image tag with an actual image in the content
     */
    private function replaceImageTagWithImage(array $imageTag, string $imageUrl, string $altText): void
    {
        $content = $this->content->content;
        $contentData = json_decode($content, true);

        if (!$contentData || !isset($contentData['blocks'])) {
            return;
        }

        // Process each block
        foreach ($contentData['blocks'] as $index => &$block) {
            if ($block['type'] === 'paragraph' && isset($block['data']['text'])) {
                $text = $block['data']['text'];

                // Check if this paragraph contains the specific image tag
                if (strpos($text, $imageTag['full_tag']) !== false) {
                    Log::info('Found matching image tag in paragraph', [
                        'content_id' => $this->content->id,
                        'original_text' => $text,
                        'tag_to_remove' => $imageTag['full_tag']
                    ]);

                    // Remove the image tag from the text
                    $remainingText = str_replace($imageTag['full_tag'], '', $text);
                    $remainingText = trim($remainingText);

                    Log::info('After tag removal', [
                        'content_id' => $this->content->id,
                        'remaining_text' => $remainingText,
                        'tag_removed' => $remainingText !== $text
                    ]);

                    // Create the image block
                    $imageBlock = [
                        'id' => 'image-' . $block['id'],
                        'type' => 'image',
                        'data' => [
                            'file' => [
                                'url' => $imageUrl
                            ],
                            'caption' => $altText,
                            'withBorder' => false,
                            'withBackground' => false,
                            'stretched' => false
                        ]
                    ];

                    // If there's remaining text, keep the paragraph and add image after it
                    if (!empty($remainingText)) {
                        // Update the paragraph with remaining text
                        $block['data']['text'] = $remainingText;

                        // Insert the image block after this paragraph
                        array_splice($contentData['blocks'], $index + 1, 0, [$imageBlock]);
                    } else {
                        // Replace the entire paragraph with the image block
                        $block = $imageBlock;
                    }

                    // Update the content immediately
                    $this->content->update([
                        'content' => json_encode($contentData)
                    ]);

                    // Verify the update worked
                    $this->content->refresh();
                    $updatedContent = $this->content->content;
                    $hasTagAfterUpdate = strpos($updatedContent, $imageTag['full_tag']) !== false;

                    Log::info('Replaced image tag with actual image', [
                        'content_id' => $this->content->id,
                        'image_url' => $imageUrl,
                        'remaining_text' => $remainingText,
                        'original_tag' => $imageTag['full_tag'],
                        'tag_still_exists' => $hasTagAfterUpdate,
                        'content_updated' => $updatedContent !== $content
                    ]);

                    return; // Exit after processing the first matching tag
                }
            }
        }
    }

    /**
     * Remove a problematic image tag to prevent infinite loops
     */
    private function removeImageTag(array $imageTag): void
    {
        $content = $this->content->content;
        $contentData = json_decode($content, true);

        if (!$contentData || !isset($contentData['blocks'])) {
            return;
        }

        // Process each block
        foreach ($contentData['blocks'] as &$block) {
            if ($block['type'] === 'paragraph' && isset($block['data']['text'])) {
                $text = $block['data']['text'];

                // Check if this paragraph contains the specific image tag
                if (strpos($text, $imageTag['full_tag']) !== false) {
                    // Remove the image tag from the text
                    $block['data']['text'] = str_replace($imageTag['full_tag'], '', $text);

                    // Update the content immediately
                    $this->content->update([
                        'content' => json_encode($contentData)
                    ]);

                    Log::warning('Removed problematic image tag', [
                        'content_id' => $this->content->id,
                        'tag' => $imageTag['full_tag']
                    ]);

                    return; // Exit after processing the first matching tag
                }
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateContentImagesJob failed permanently', [
            'content_id' => $this->content->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
