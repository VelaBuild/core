<?php

namespace VelaBuild\Core\Http\Controllers;

use VelaBuild\Core\Services\ImageOptimizer;

class ImageController extends Controller
{
    public function webp(string $config)
    {
        $optimizer = app(ImageOptimizer::class);
        $decoded = $optimizer->verifyAndDecode($config);

        if ($decoded === null) {
            abort(403, 'Invalid image signature');
        }

        $result = $optimizer->process($decoded, webp: true);

        if (isset($result['error'])) {
            if ($result['error'] === 'not_found') {
                abort(404);
            }
            abort(500);
        }

        return response()->file($result['path'], [
            'Content-Type' => $result['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function resize(string $config)
    {
        $optimizer = app(ImageOptimizer::class);
        $decoded = $optimizer->verifyAndDecode($config);

        if ($decoded === null) {
            abort(403, 'Invalid image signature');
        }

        $result = $optimizer->process($decoded, webp: false);

        if (isset($result['error'])) {
            if ($result['error'] === 'not_found') {
                abort(404);
            }
            abort(500);
        }

        return response()->file($result['path'], [
            'Content-Type' => $result['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
