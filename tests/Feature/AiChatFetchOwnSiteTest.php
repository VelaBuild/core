<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Services\AiChat\Tools\FetchUrlTool;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * Reading the page a visitor would get is the one reliable way to check a
 * change landed — and the SSRF guard blocked it, because on a local or
 * intranet install the site's own address is private by definition. Asked
 * whether the footer year updates itself, the chatbot could search the source
 * but never simply look.
 */
class AiChatFetchOwnSiteTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.url', 'http://127.0.0.1:8000');
    }

    public function test_the_site_may_read_its_own_pages(): void
    {
        $result = (new FetchUrlTool())->execute(['url' => 'http://127.0.0.1:8000/']);

        $this->assertArrayNotHasKey('error', $result, 'the chatbot must be able to look at the site it runs inside');
    }

    public function test_another_service_on_the_same_machine_stays_blocked(): void
    {
        // A different port on loopback is someone else's app, not this site.
        $result = (new FetchUrlTool())->execute(['url' => 'http://127.0.0.1:3306/']);

        $this->assertStringContainsString('private/loopback', $result['error']);
    }

    public function test_the_rest_of_the_network_stays_blocked(): void
    {
        $result = (new FetchUrlTool())->execute(['url' => 'http://192.168.1.1/']);

        $this->assertStringContainsString('private/loopback', $result['error']);
    }
}
