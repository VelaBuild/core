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
        $payload = $request->getContent();
        $data = json_decode($payload, true) ?: [];

        // The marketplace signs each event with the license key of the
        // purchase it is about, as "sha256=<hex>". Only a site-wide secret,
        // compared without the prefix, was accepted here, so every update
        // notice was refused and "Update available" never appeared.
        $secrets = array_values(array_filter([
            $this->settings->get('webhook_secret'),
            InstalledPackage::with('license')->where('composer_name', $data['plugin'] ?? '')->first()?->license?->license_key,
        ]));

        if (!$secrets) {
            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        $signature = preg_replace('/^sha256=/', '', (string) $request->header('X-Marketplace-Signature', ''));
        $valid = $signature !== '' && collect($secrets)->contains(
            fn ($secret) => hash_equals(hash_hmac('sha256', $payload, $secret), $signature)
        );

        if (!$valid) {
            Log::warning('Marketplace webhook: invalid signature');
            return response()->json(['error' => 'Invalid signature'], 401);
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
