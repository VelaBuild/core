<?php

namespace VelaBuild\Core\Tests\Unit\Helpers;

use VelaBuild\Core\Tests\TestCase;
use Illuminate\Support\Facades\View;

class TemplateHelperTest extends TestCase
{
    public function test_vela_template_view_returns_active_template(): void
    {
        config(['vela.template.active' => 'default']);

        // Code checks host templates first, then package templates
        View::shouldReceive('exists')
            ->with('templates.default.home')
            ->andReturn(false);

        View::shouldReceive('exists')
            ->with('vela::templates.default.home')
            ->andReturn(true);

        $result = vela_template_view('home');

        $this->assertEquals('vela::templates.default.home', $result);
    }

    public function test_vela_template_view_falls_back_to_default(): void
    {
        config(['vela.template.active' => 'nonexistent']);

        // Code checks host templates first
        View::shouldReceive('exists')
            ->with('templates.nonexistent.home')
            ->andReturn(false);

        View::shouldReceive('exists')
            ->with('templates.default.home')
            ->andReturn(false);

        // Then package templates
        View::shouldReceive('exists')
            ->with('vela::templates.nonexistent.home')
            ->andReturn(false);

        View::shouldReceive('exists')
            ->with('vela::templates.default.home')
            ->andReturn(true);

        $result = vela_template_view('home');

        $this->assertEquals('vela::templates.default.home', $result);
    }

    public function test_vela_template_layout_returns_active_layout(): void
    {
        config(['vela.template.active' => 'default']);

        // Code checks host templates first, then package templates
        View::shouldReceive('exists')
            ->with('templates.default.layout')
            ->andReturn(false);

        View::shouldReceive('exists')
            ->with('vela::templates.default.layout')
            ->andReturn(true);

        $result = vela_template_layout();

        $this->assertEquals('vela::templates.default.layout', $result);
    }
}
