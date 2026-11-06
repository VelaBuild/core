<?php

namespace VelaBuild\Core\Tests\Unit;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Tests\TestCase;

class CdnPurgeCommandTest extends TestCase
{
    private const ENV = [
        'VELA_GATEWAY_URL'    => 'https://ai.vela.test',
        'VELA_GATEWAY_SITE'   => 'acme',
        'VELA_GATEWAY_SECRET' => 'vsk_test_secret',
    ];

    protected function tearDown(): void
    {
        foreach (self::ENV as $k => $v) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        parent::tearDown();
    }

    public function test_a_hosted_site_purges_all_its_pages_through_the_gateway(): void
    {
        foreach (self::ENV as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        Http::fake(['ai.vela.test/*' => Http::response(['purged' => ['tags' => 1, 'urls' => 0, 'hosts' => 0], 'skipped' => [], 'errors' => []])]);

        $this->artisan('vela:cdn-purge')->expectsOutput('CDN purged.')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://ai.vela.test/v1/cdn/purge' && $r->data()['tags'] === ['site']);
    }

    public function test_without_a_cdn_it_does_nothing(): void
    {
        Http::fake();
        $this->artisan('vela:cdn-purge')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_failed_purge_fails_the_command(): void
    {
        foreach (self::ENV as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        Http::fake(['ai.vela.test/*' => Http::response(['error' => 'nope'], 502)]);

        $this->artisan('vela:cdn-purge')->assertFailed();
    }
}
