<?php

namespace VelaBuild\Core\Tests\Feature;

use VelaBuild\Core\Tests\TestCase;

class CsrfTokenApiTest extends TestCase
{
    public function test_csrf_token_endpoint_returns_token(): void
    {
        $response = $this->get('/api/csrf-token');

        $response->assertStatus(200);
        $response->assertJsonStructure(['token']);

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }
}
