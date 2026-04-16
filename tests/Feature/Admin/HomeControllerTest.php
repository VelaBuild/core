<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use VelaBuild\Core\Tests\TestCase;

class HomeControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        $this->loginAsAdmin();

        $response = $this->get('/admin');
        $response->assertStatus(200);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get('/admin');
        $response->assertRedirect(route('vela.auth.login'));
    }
}
