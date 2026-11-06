<?php

namespace VelaBuild\Core\Tests\Unit;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Jobs\GenerateStaticFilesJob;
use VelaBuild\Core\Tests\TestCase;

/** An edit is live as soon as its static rebuild is written: the CDN is purged then, not later. */
class StaticRebuildPurgesCdnTest extends TestCase
{
    private const ENV = [
        'VELA_GATEWAY_URL'    => 'https://ai.vela.test',
        'VELA_GATEWAY_SITE'   => 'acme',
        'VELA_GATEWAY_SECRET' => 'vsk_test_secret',
    ];

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/vela-static-purge-' . uniqid();
        config(['vela.static.path' => $this->tempPath, 'vela.static.enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach (self::ENV as $k => $v) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        if (is_dir($this->tempPath)) {
            exec('rm -rf ' . escapeshellarg($this->tempPath));
        }
        parent::tearDown();
    }

    private function hosted(): void
    {
        foreach (self::ENV as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
    }

    public function test_the_same_rebuild_asked_for_twice_in_one_request_runs_once(): void
    {
        Bus::fake();
        GenerateStaticFilesJob::dispatchFresh('all');
        GenerateStaticFilesJob::dispatchFresh('all');
        GenerateStaticFilesJob::dispatchFresh('home');

        Bus::assertDispatchedAfterResponseTimes(GenerateStaticFilesJob::class, 2);
    }

    public function test_a_hosted_site_purges_its_cdn_right_after_the_last_rebuild(): void
    {
        $this->hosted();
        Http::fake(['ai.vela.test/*' => Http::response(['purged' => ['tags' => 1, 'urls' => 0, 'hosts' => 0], 'skipped' => [], 'errors' => []])]);

        GenerateStaticFilesJob::dispatchFresh('home');
        GenerateStaticFilesJob::dispatchFresh('posts_index');
        $this->app->terminate();

        $purges = Http::recorded(fn (HttpRequest $r) => $r->url() === 'https://ai.vela.test/v1/cdn/purge');
        $this->assertCount(1, $purges, 'one purge, after both rebuilds');
        $this->assertSame(['site'], $purges[0][0]->data()['tags']);
    }

    public function test_without_a_cdn_nothing_is_purged(): void
    {
        Http::fake();
        (new GenerateStaticFilesJob('home'))->handle();

        Http::assertNothingSent();
    }
}
