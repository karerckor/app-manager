<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_platform_values(): void
    {
        $this->assertSame('ios', Platform::Ios->value);
        $this->assertSame('android', Platform::Android->value);
        $this->assertSame('web', Platform::Web->value);
    }

    public function test_profile_type_values(): void
    {
        $this->assertSame('appstore', ProfileType::AppStore->value);
        $this->assertSame('adhoc', ProfileType::AdHoc->value);
        $this->assertSame('preview', ProfileType::Preview->value);
        $this->assertSame(ProfileType::Development, ProfileType::from('development'));
    }

    public function test_credential_type_values(): void
    {
        $this->assertSame('ios_certificate', CredentialType::IosCertificate->value);
        $this->assertSame('provisioning_profile', CredentialType::ProvisioningProfile->value);
        $this->assertSame('google_play_service_account', CredentialType::GooglePlayServiceAccount->value);
        $this->assertSame('google_services_json', CredentialType::GoogleServicesJson->value);
        $this->assertSame('google_service_info_plist', CredentialType::GoogleServiceInfoPlist->value);
        $this->assertCount(9, CredentialType::cases());
    }
}
