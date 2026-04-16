<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class SharedPartialsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_template_renders_with_partials(): void
    {
        config(['vela.template.active' => 'default']);
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('<meta charset="utf-8">', false);
        $response->assertSee('og:type', false);
        $response->assertSee('hreflang', false);
    }

    public function test_minimal_template_renders_with_partials(): void
    {
        config(['vela.template.active' => 'minimal']);
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('<meta charset="utf-8">', false);
        $response->assertSee('og:type', false);
        $response->assertSee('hreflang', false);
    }

    public function test_nav_pages_available_in_layout(): void
    {
        config(['vela.template.active' => 'default']);
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('<nav', false);
    }

    public function test_corporate_theme_renders(): void
    {
        config(['vela.template.active' => 'corporate']);
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('<nav', false);
        $response->assertSee('<main', false);
        $response->assertSee('<footer', false);
    }

    public function test_editorial_theme_renders(): void
    {
        config(['vela.template.active' => 'editorial']);
        $response = $this->get('/');
        $response->assertStatus(200);
    }

    public function test_modern_theme_renders(): void
    {
        config(['vela.template.active' => 'modern']);
        $response = $this->get('/');
        $response->assertStatus(200);
    }

    public function test_dark_theme_renders(): void
    {
        config(['vela.template.active' => 'dark']);
        $response = $this->get('/');
        $response->assertStatus(200);
    }
}
