<?php

namespace VelaBuild\Core\Tests\Feature\Public;

use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;

class OfflinePageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_offline_page_renders(): void
    {
        // Use a non-existent template so OfflineController falls back to
        // vela::pwa.offline (standalone page without template layout dependencies)
        Config::set('vela.template.active', 'none');

        $response = $this->get('/offline');
        $response->assertStatus(200);
        $response->assertSee('offline', false);
    }
}
