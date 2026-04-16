<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use VelaBuild\Core\Jobs\ImportContentFromConfigJob;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\TestCase;

class LoginImportTriggerTest extends TestCase
{
    public function test_login_dispatches_import_job(): void
    {
        Queue::fake();

        $password = 'password';
        $user = VelaUser::factory()->create([
            'password' => bcrypt($password),
        ]);

        $prefix = config('vela.auth_prefix', 'vela');
        $this->post('/' . $prefix . '/login', [
            'email'    => $user->email,
            'password' => $password,
        ]);

        Queue::assertPushed(ImportContentFromConfigJob::class);
    }
}
