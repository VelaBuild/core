<?php

namespace VelaBuild\Core\Tests\Unit\Marketplace;

use VelaBuild\Core\Models\PackageLicense;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Services\Marketplace\LicenseManager;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class LicenseManagerTest extends TestCase
{
    use DatabaseTransactions;

    private LicenseManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $client = $this->createMock(MarketplaceClient::class);
        $cacheWriter = $this->createMock(LicenseCacheWriter::class);

        $this->manager = new LicenseManager($client, $cacheWriter);
    }

    // -------------------------------------------------------------------------
    // Domain validation tests
    // -------------------------------------------------------------------------

    public function test_is_license_valid_for_production_domain(): void
    {
        $license = new PackageLicense();
        $license->domain = 'example.com';
        $license->dev_domain = null;

        $this->assertTrue($this->manager->isLicenseValidForDomain($license, 'example.com'));
    }

    public function test_is_license_valid_for_dev_domain(): void
    {
        $license = new PackageLicense();
        $license->domain = 'example.com';
        $license->dev_domain = 'dev.example.com';

        $this->assertTrue($this->manager->isLicenseValidForDomain($license, 'dev.example.com'));
    }

    public function test_is_license_valid_for_localhost(): void
    {
        $license = new PackageLicense();
        $license->domain = 'example.com';
        $license->dev_domain = null;

        $this->assertTrue($this->manager->isLicenseValidForDomain($license, 'localhost'));
    }

    public function test_is_license_valid_for_dot_test(): void
    {
        $license = new PackageLicense();
        $license->domain = 'example.com';
        $license->dev_domain = null;

        $this->assertTrue($this->manager->isLicenseValidForDomain($license, 'mysite.test'));
    }

    public function test_is_license_valid_for_dot_local(): void
    {
        $license = new PackageLicense();
        $license->domain = 'example.com';
        $license->dev_domain = null;

        $this->assertTrue($this->manager->isLicenseValidForDomain($license, 'mysite.local'));
    }

    public function test_is_license_valid_for_127_0_0_1(): void
    {
        $license = new PackageLicense();
        $license->domain = 'example.com';
        $license->dev_domain = null;

        $this->assertTrue($this->manager->isLicenseValidForDomain($license, '127.0.0.1'));
    }

    public function test_is_license_invalid_for_wrong_domain(): void
    {
        $license = new PackageLicense();
        $license->domain = 'example.com';
        $license->dev_domain = null;

        $this->assertFalse($this->manager->isLicenseValidForDomain($license, 'other.com'));
    }

    // -------------------------------------------------------------------------
    // Expiry tests
    // -------------------------------------------------------------------------

    public function test_yearly_license_not_expired_before_date(): void
    {
        $license = new PackageLicense();
        $license->type = PackageLicense::TYPE_YEARLY;
        $license->expires_at = now()->addYear();

        $this->assertFalse($this->manager->isExpired($license));
    }

    public function test_yearly_license_expired_after_date(): void
    {
        $license = new PackageLicense();
        $license->type = PackageLicense::TYPE_YEARLY;
        $license->expires_at = now()->subDay();

        $this->assertTrue($this->manager->isExpired($license));
    }

    public function test_lifetime_license_never_expires(): void
    {
        $license = new PackageLicense();
        $license->type = PackageLicense::TYPE_ONETIME;
        $license->expires_at = null;

        $this->assertFalse($this->manager->isExpired($license));
    }

    public function test_free_license_never_expires(): void
    {
        $license = new PackageLicense();
        $license->type = PackageLicense::TYPE_FREE;
        $license->expires_at = now()->subYear();

        $this->assertFalse($this->manager->isExpired($license));
    }

    // -------------------------------------------------------------------------
    // Safe mode tests
    // -------------------------------------------------------------------------

    public function test_safe_mode_detected_from_env(): void
    {
        putenv('VELA_SAFE_MODE=true');

        try {
            $this->assertTrue($this->manager->isSafeMode());
        } finally {
            putenv('VELA_SAFE_MODE=');
        }
    }

    public function test_safe_mode_detected_from_flag_file(): void
    {
        $flagFile = storage_path('app/.vela-safe-mode');

        // Ensure env is NOT set
        putenv('VELA_SAFE_MODE=');

        file_put_contents($flagFile, '');

        try {
            $this->assertTrue($this->manager->isSafeMode());
        } finally {
            @unlink($flagFile);
        }
    }

    public function test_not_safe_mode_when_no_indicators(): void
    {
        putenv('VELA_SAFE_MODE=');

        $flagFile = storage_path('app/.vela-safe-mode');
        @unlink($flagFile);

        $this->assertFalse($this->manager->isSafeMode());
    }
}
