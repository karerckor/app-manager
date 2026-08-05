<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_closed_by_default_even_when_the_installer_is_disabled(): void
    {
        config([
            'installer.public_registration' => false,
            'installer.enabled' => false,
        ]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'Sup3rSecret!',
            'password_confirmation' => 'Sup3rSecret!',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_is_reachable_when_explicitly_allowed(): void
    {
        config([
            'installer.public_registration' => true,
            'installer.enabled' => false,
        ]);

        $this->get('/register')->assertSuccessful();
    }
}
