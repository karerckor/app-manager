<?php

namespace Tests\Unit;

use App\Enums\CredentialApiProfile;
use InvalidArgumentException;
use Tests\TestCase;

class CredentialApiProfileTest extends TestCase
{
    public function test_an_empty_or_unset_value_defaults_to_the_full_profile(): void
    {
        config(['credentials.api_profile' => '']);
        $this->assertSame(CredentialApiProfile::Full, CredentialApiProfile::current());

        config(['credentials.api_profile' => null]);
        $this->assertSame(CredentialApiProfile::Full, CredentialApiProfile::current());
    }

    public function test_an_unrecognised_value_is_a_hard_failure_rather_than_a_silent_fallback(): void
    {
        config(['credentials.api_profile' => 'dashboard-only']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown CREDENTIALS_API_PROFILE [dashboard-only]. Expected 'full' or 'dashboard_only'.");

        CredentialApiProfile::current();
    }
}
