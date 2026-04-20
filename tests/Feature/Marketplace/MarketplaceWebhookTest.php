<?php

namespace VelaBuild\Core\Tests\Feature\Marketplace;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService;
use VelaBuild\Core\Tests\TestCase;

class MarketplaceWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private string $secret = 'test-webhook-secret';

    private function buildRequest(array $data, string $secret): array
    {
        $payload = json_encode($data);
        $signature = hash_hmac('sha256', $payload, $secret);

        return [$payload, $signature];
    }

    public function test_valid_signature_accepted(): void
    {
        $settings = app(MarketplaceSettingsService::class);
        $settings->set('webhook_secret', $this->secret);

        $data = ['event' => 'version.released', 'plugin' => 'acme/test', 'version' => '1.0.0'];
        [$payload, $signature] = $this->buildRequest($data, $this->secret);

        $response = $this->call(
            'POST',
            '/webhook/marketplace',
            [],
            [],
            [],
            ['HTTP_X-Marketplace-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_invalid_signature_rejected(): void
    {
        $settings = app(MarketplaceSettingsService::class);
        $settings->set('webhook_secret', $this->secret);

        $data = ['event' => 'version.released', 'plugin' => 'acme/test', 'version' => '1.0.0'];
        [$payload, ] = $this->buildRequest($data, $this->secret);

        $response = $this->call(
            'POST',
            '/webhook/marketplace',
            [],
            [],
            [],
            ['HTTP_X-Marketplace-Signature' => 'wrong-signature', 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(401);
    }

    public function test_missing_signature_rejected(): void
    {
        $settings = app(MarketplaceSettingsService::class);
        $settings->set('webhook_secret', $this->secret);

        $response = $this->postJson('/webhook/marketplace', [
            'event' => 'version.released',
            'plugin' => 'acme/test',
            'version' => '1.0.0',
        ]);

        $response->assertStatus(401);
    }

    public function test_unconfigured_webhook_returns_503(): void
    {
        $settings = app(MarketplaceSettingsService::class);
        $settings->set('webhook_secret', null);

        $response = $this->postJson('/webhook/marketplace', [
            'event' => 'version.released',
            'plugin' => 'acme/test',
        ]);

        $response->assertStatus(503);
    }

    public function test_version_released_event_stores_update_info(): void
    {
        $settings = app(MarketplaceSettingsService::class);
        $settings->set('webhook_secret', $this->secret);

        $composerName = 'acme/test-' . uniqid();

        InstalledPackage::create([
            'vendor_name' => 'acme',
            'package_name' => 'test',
            'composer_name' => $composerName,
            'version' => '1.0.0',
            'status' => InstalledPackage::STATUS_ACTIVE,
            'installed_at' => now(),
        ]);

        $data = [
            'event' => 'version.released',
            'plugin' => $composerName,
            'version' => '1.1.0',
            'changelog' => 'Bug fixes and improvements.',
        ];
        [$payload, $signature] = $this->buildRequest($data, $this->secret);

        $this->call(
            'POST',
            '/webhook/marketplace',
            [],
            [],
            [],
            ['HTTP_X-Marketplace-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $configKey = 'marketplace_update_' . str_replace('/', '_', $composerName);
        $this->assertDatabaseHas('vela_configs', [
            'key' => $configKey,
        ]);
    }

    public function test_license_revoked_event_suspends_package(): void
    {
        $settings = app(MarketplaceSettingsService::class);
        $settings->set('webhook_secret', $this->secret);

        $composerName = 'acme/revoked-' . uniqid();

        $package = InstalledPackage::create([
            'vendor_name' => 'acme',
            'package_name' => 'revoked',
            'composer_name' => $composerName,
            'version' => '1.0.0',
            'status' => InstalledPackage::STATUS_ACTIVE,
            'installed_at' => now(),
        ]);

        $data = [
            'event' => 'license.revoked',
            'plugin' => $composerName,
        ];
        [$payload, $signature] = $this->buildRequest($data, $this->secret);

        $this->call(
            'POST',
            '/webhook/marketplace',
            [],
            [],
            [],
            ['HTTP_X-Marketplace-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $this->assertDatabaseHas('vela_installed_packages', [
            'id' => $package->id,
            'status' => InstalledPackage::STATUS_SUSPENDED,
        ]);
    }
}
