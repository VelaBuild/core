<?php

namespace VelaBuild\Core\Tests\Feature\Auth;

use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\TestCase;

class TwoFactorTest extends TestCase
{
    public function test_two_factor_page_renders_when_code_exists(): void
    {
        $user = VelaUser::factory()->create([
            'two_factor' => true,
            'two_factor_code' => '123456',
            'two_factor_expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user, 'vela');

        $response = $this->get(route('vela.auth.two-factor.show'));
        $response->assertStatus(200);
    }

    public function test_correct_code_passes(): void
    {
        $user = VelaUser::factory()->create([
            'two_factor' => true,
            'two_factor_code' => '654321',
            'two_factor_expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user, 'vela');

        $response = $this->post(route('vela.auth.two-factor.check'), [
            'two_factor_code' => '654321',
        ]);

        $response->assertRedirect(route('vela.admin.home'));
    }

    public function test_incorrect_code_fails(): void
    {
        $user = VelaUser::factory()->create([
            'two_factor' => true,
            'two_factor_code' => '111111',
            'two_factor_expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user, 'vela');

        $response = $this->post(route('vela.auth.two-factor.check'), [
            'two_factor_code' => '999999',
        ]);

        $response->assertSessionHasErrors();
    }
}
