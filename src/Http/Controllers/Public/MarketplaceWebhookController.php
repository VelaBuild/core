<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService;

class MarketplaceWebhookController extends Controller
{
    public function __construct(
        private MarketplaceSettingsService $settings,
        private LicenseCacheWriter $cacheWriter,
    ) {}

    public function handle(Request $request)
    {
        $secret = $this->settings->get('webhook_secret');

        if (!$secret) {
            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        // Verify webhook signature
        $signature = $request->header('X-Marketplace-Signature');
        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!$signature || !hash_equals($expected, $signature)) {
            Log::warning('Marketplace webhook: invalid signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data = json_decode($payload, true);

        if (!is_array($data)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        switch ($data['event'] ?? null) {
            case 'version.released':
                $package = InstalledPackage::where('composer_name', $data['plugin'] ?? '')->first();
                if ($package) {
                    $configKey = 'marketplace_update_' . str_replace('/', '_', $data['plugin']);
                    VelaConfig::updateOrCreate(
                        ['key' => $configKey],
                        ['value' => json_encode([
                            'version' => $data['version'] ?? '',
                            'changelog' => $data['changelog'] ?? '',
                        ])]
                    );
                    Log::info('Marketplace webhook: update available', [
                        'plugin' => $data['plugin'],
                        'version' => $data['version'] ?? '',
                    ]);
                }
                break;

            case 'license.revoked':
                $package = InstalledPackage::where('composer_name', $data['plugin'] ?? '')->first();
                if ($package) {
                    $package->update(['status' => 'suspended']);
                    $this->cacheWriter->write();
                    Log::info('Marketplace webhook: license revoked', ['plugin' => $data['plugin']]);
                }
                break;

            case 'license.renewed':
                $package = InstalledPackage::where('composer_name', $data['plugin'] ?? '')->first();
                if ($package && $package->license) {
                    $package->license->update([
                        'expires_at' => $data['expires_at'] ?? null,
                        'validation_status' => 'valid',
                    ]);
                    $this->cacheWriter->write();
                    Log::info('Marketplace webhook: license renewed', ['plugin' => $data['plugin']]);
                }
                break;

            default:
                Log::info('Marketplace webhook: unknown event', ['event' => $data['event'] ?? null]);
                break;
        }

        return response()->json(['success' => true], 200);
    }
}
