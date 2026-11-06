<?php

namespace VelaBuild\Core\Tests\Unit;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\CacheTagger;
use VelaBuild\Core\Services\Tools\CloudflareService;
use VelaBuild\Core\Tests\TestCase;

/** Hosted sites purge through the gateway; their Cache-Tag header is namespaced. */
class CdnPurgeViaGatewayTest extends TestCase
{
    private const ENV = [
        'VELA_GATEWAY_URL'    => 'https://ai.vela.test',
        'VELA_GATEWAY_SITE'   => 'acme',
        'VELA_GATEWAY_SECRET' => 'vsk_test_secret',
    ];

    private function hosted(bool $on): void
    {
        foreach (self::ENV as $k => $v) {
            if ($on) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $_SERVER[$k] = $v;
            } else {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->hosted(false);
        parent::tearDown();
    }

    public function test_hosted_purges_go_to_the_gateway_signed_and_never_to_cloudflare(): void
    {
        $this->hosted(true);
        Http::fake(['ai.vela.test/*' => Http::response(['purged' => ['tags' => 2, 'urls' => 0, 'hosts' => 0], 'skipped' => [], 'errors' => []])]);
        $cf = app(CloudflareService::class);

        $this->assertTrue($cf->isConfigured(), 'no Cloudflare token needed on a hosted site');
        $cf->purgeByTags(['page:1', 'site']);
        $cf->purgeUrls(['https://acme.velasites.dev/about']);
        $cf->purgeAll();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://ai.vela.test/v1/cdn/purge'
            && ($r->data()['tags'] ?? null) === ['page:1', 'site'] && $r->hasHeader('X-Vela-Signature'));
        Http::assertSent(fn (HttpRequest $r) => ($r->data()['urls'] ?? null) === ['https://acme.velasites.dev/about']);
        Http::assertSent(fn (HttpRequest $r) => ($r->data()['everything'] ?? null) === true);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.cloudflare.com'));
    }

    public function test_self_hosted_still_calls_cloudflare_directly(): void
    {
        $this->hosted(false);
        $this->assertFalse(app(CloudflareService::class)->isConfigured(), 'unconfigured without a token');
    }

    public function test_cache_tag_header_is_namespaced_only_on_hosted_sites(): void
    {
        $tagger = new CacheTagger();
        $tagger->add(['page:1', 'site']);

        config(['vela.hosting.site_id' => null]);
        $this->assertSame('page:1,site', $tagger->header());

        config(['vela.hosting.site_id' => 'Acme']);
        $this->assertSame('s-acme-page:1,s-acme-site', $tagger->header());
    }
}
