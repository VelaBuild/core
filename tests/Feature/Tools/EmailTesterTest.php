<?php

namespace VelaBuild\Core\Tests\Feature\Tools;

use Illuminate\Support\Facades\Mail;
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class EmailTesterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_send_test_email(): void
    {
        Mail::fake();
        $this->loginAsAdmin();

        $response = $this->postJson(route('vela.admin.tools.email-tester.send'), [
            'to' => 'test@example.com',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_rate_limiting(): void
    {
        Mail::fake();
        $this->loginAsAdmin();

        // Send 5 emails (limit)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('vela.admin.tools.email-tester.send'), [
                'to' => 'test@example.com',
            ]);
        }

        // 6th should be rate limited
        $response = $this->postJson(route('vela.admin.tools.email-tester.send'), [
            'to' => 'test@example.com',
        ]);
        $response->assertStatus(429);
    }

    public function test_invalid_email_rejected(): void
    {
        $this->loginAsAdmin();

        $response = $this->postJson(route('vela.admin.tools.email-tester.send'), [
            'to' => 'not-an-email',
        ]);
        $response->assertStatus(422);
    }
}
