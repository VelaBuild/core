<?php
namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\AiProviderManager;

class GenerateImage extends Command
{
    protected $signature = 'vela:generate-image
                            {--prompt= : Image generation prompt}
                            {--type=content : Image type: logo, hero, content}
                            {--size=1024x1024 : Image size (OpenAI) or aspect ratio (Gemini)}
                            {--provider= : Force specific provider: openai or gemini}
                            {--output= : Output file path (default: public/images/generated-{timestamp}.png)}
                            {--dry-run : Show prompt without generating}';

    protected $description = 'Generate images using AI';

    private AiProviderManager $aiManager;

    public function __construct(AiProviderManager $aiManager)
    {
        parent::__construct();
        $this->aiManager = $aiManager;
    }

    public function handle(): int
    {
        if (!$this->aiManager->hasImageProvider()) {
            $this->error('No AI image provider configured. Set OPENAI_API_KEY or GEMINI_API_KEY in .env');
            return 1;
        }

        $prompt = $this->option('prompt') ?? $this->ask('Describe the image you want');
        $type = $this->option('type');
        $size = $this->option('size');
        $providerName = $this->option('provider');
        $output = $this->option('output') ?? public_path('images/generated-' . now()->format('Y-m-d-H-i-s') . '.png');

        if ($this->option('dry-run')) {
            $this->info("Dry run - would generate image:");
            $this->line("Prompt: {$prompt}");
            $this->line("Type: {$type}, Size: {$size}");
            $this->line("Output: {$output}");
            return 0;
        }

        $imageService = $this->aiManager->resolveImageProvider($providerName);

        $this->info('Generating image...');
        $options = ['size' => $size, 'aspect_ratio' => $size, 'quality' => 'high'];
        $response = $imageService->generateImage($prompt, $options);

        if (!$response || !isset($response['data'][0]['b64_json']) || empty($response['data'][0]['b64_json'])) {
            $this->error('Failed to generate image.');
            return 1;
        }

        $imageData = base64_decode($response['data'][0]['b64_json']);
        if ($imageData === false) {
            $this->error('Failed to decode image data.');
            return 1;
        }

        // Atomic write
        $tmpPath = $output . '.tmp';
        $dir = dirname($output);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($tmpPath, $imageData);
        rename($tmpPath, $output);

        $sizeFormatted = number_format(filesize($output) / 1024, 1) . ' KB';
        $this->info("Image saved: {$output} ({$sizeFormatted})");
        $this->line(json_encode(['path' => $output, 'size' => filesize($output)]));

        return 0;
    }
}
