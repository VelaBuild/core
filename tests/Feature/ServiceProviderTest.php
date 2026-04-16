<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\TestCase;
use Illuminate\Routing\Router;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('vela.admin_prefix'));
        $this->assertEquals('admin', config('vela.admin_prefix'));
    }

    public function test_routes_are_registered(): void
    {
        $this->assertTrue(
            collect(app('router')->getRoutes()->getRoutesByName())->has('vela.admin.home'),
            'Route vela.admin.home is not registered'
        );

        $this->assertTrue(
            collect(app('router')->getRoutes()->getRoutesByName())->has('vela.auth.login'),
            'Route vela.auth.login is not registered'
        );

        $this->assertTrue(
            collect(app('router')->getRoutes()->getRoutesByName())->has('vela.public.home'),
            'Route vela.public.home is not registered'
        );
    }

    public function test_views_are_registered(): void
    {
        $this->assertTrue(
            view()->exists('vela::admin.home'),
            'View vela::admin.home does not exist'
        );
    }

    public function test_middleware_aliases_registered(): void
    {
        /** @var Router $router */
        $router = app(Router::class);
        $middleware = $router->getMiddleware();

        $this->assertArrayHasKey('vela.auth', $middleware);
        $this->assertArrayHasKey('vela.2fa', $middleware);
        $this->assertArrayHasKey('vela.gates', $middleware);
        $this->assertArrayHasKey('vela.locale', $middleware);
    }
}
