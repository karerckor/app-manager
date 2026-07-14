<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_can_be_shared_across_multiple_app_identifiers(): void
    {
        $credential = Credential::factory()->create(['type' => CredentialType::IosCertificate]);
        $first = AppIdentifier::factory()->create();
        $second = AppIdentifier::factory()->create();

        $credential->appIdentifiers()->attach([$first->id, $second->id]);

        $this->assertCount(2, $credential->appIdentifiers);
        $this->assertTrue($first->credentials->contains($credential));
        $this->assertTrue($second->credentials->contains($credential));
    }

    public function test_provisioning_profile_credential_binds_to_a_profile(): void
    {
        $credential = Credential::factory()->create(['type' => CredentialType::ProvisioningProfile]);
        $profile = Profile::factory()->create();

        $credential->profiles()->attach($profile);

        $this->assertTrue($credential->profiles->contains($profile));
        $this->assertTrue($profile->credentials->contains($credential));
    }
}
