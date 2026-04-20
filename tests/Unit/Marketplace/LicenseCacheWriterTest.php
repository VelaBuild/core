<?php

namespace VelaBuild\Core\Tests\Unit\Marketplace;

use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Models\PackageLicense;
use VelaBuild\Core\Services\Marketplace\LicenseCacheWriter;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class LicenseCacheWriterTest extends TestCase
{
    use DatabaseTransactions;

    private LicenseCacheWriter $writer;
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = new LicenseCacheWriter();
        $this->cachePath = storage_path('app/vela-licenses.php');
    }

    protected function tearDown(): void
    {
        // Remove the cache file after each test so state doesn't bleed across tests
        @unlink($this->cachePath);
        @unlink($this->cachePath . '.tmp');

        parent::tearDown();
    }

    public function test_write_creates_valid_php_file(): void
    {
        $package = InstalledPackage::create([
            'vendor_name'   => 'acme',
            'package_name'  => 'test-plugin',
            'composer_name' => 'acme/test-plugin',
            'version'       => '1.0.0',
            'status'        => InstalledPackage::STATUS_ACTIVE,
            'installed_at'  => now(),
        ]);

        PackageLicense::create([
            'installed_package_id' => $package->id,
            'license_key'          => 'vela_' . str_repeat('a', 40),
            'domain'               => 'example.com',
            'dev_domain'           => 'example.test',
            'type'                 => PackageLicense::TYPE_YEARLY,
            'expires_at'           => now()->addYear(),
            'validation_status'    => PackageLicense::VALIDATION_VALID,
        ]);

        $this->writer->write();

        $this->assertFileExists($this->cachePath);

        $data = include $this->cachePath;

        $this->assertIsArray($data);
        $this->assertArrayHasKey('acme/test-plugin', $data);
        $this->assertTrue($data['acme/test-plugin']['valid']);
        $this->assertEquals('yearly', $data['acme/test-plugin']['type']);
        $this->assertEquals('example.com', $data['acme/test-plugin']['domain']);
        $this->assertEquals('example.test', $data['acme/test-plugin']['dev_domain']);
        $this->assertEquals(InstalledPackage::STATUS_ACTIVE, $data['acme/test-plugin']['status']);
    }

    public function test_read_returns_empty_array_when_file_missing(): void
    {
        @unlink($this->cachePath);

        $result = $this->writer->read();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_write_uses_atomic_rename(): void
    {
        // Write once to create the file
        $package = InstalledPackage::create([
            'vendor_name'   => 'acme',
            'package_name'  => 'atomic-test',
            'composer_name' => 'acme/atomic-test',
            'version'       => '1.0.0',
            'status'        => InstalledPackage::STATUS_ACTIVE,
            'installed_at'  => now(),
        ]);

        PackageLicense::create([
            'installed_package_id' => $package->id,
            'license_key'          => 'vela_' . str_repeat('b', 40),
            'domain'               => 'atomic.com',
            'dev_domain'           => null,
            'type'                 => PackageLicense::TYPE_FREE,
            'validation_status'    => PackageLicense::VALIDATION_VALID,
        ]);

        $this->writer->write();

        // The final file should exist
        $this->assertFileExists($this->cachePath);

        // The .tmp file should NOT exist after a successful write (was renamed)
        $this->assertFileDoesNotExist($this->cachePath . '.tmp');
    }
}
