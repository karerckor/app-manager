<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_contributor_can_attach_and_detach_identifier_scoped_credential(): void
    {
        [$user, $team] = $this->member('contributor');
        $identifier = $this->identifier($team, Platform::Ios);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);
        $storeUrl = route('accounts.credentials.attachments.store', [$team, $credential]);

        $this->actingAs($user)->post($storeUrl, [
            'app_identifier_id' => $identifier->id,
        ])->assertRedirect()->assertSessionHas('status', 'Credential attached.');
        $this->post($storeUrl, ['app_identifier_id' => $identifier->id])->assertRedirect();

        $this->assertSame(1, $identifier->credentials()->whereKey($credential->id)->count());

        $this->delete(route('accounts.credentials.attachments.destroy', [
            $team,
            $credential,
            'kind' => 'identifier',
            'attachment' => $identifier,
        ]))->assertRedirect()->assertSessionHas('status', 'Credential detached.');

        $this->assertFalse($identifier->credentials()->whereKey($credential->id)->exists());
    }

    public function test_profile_scoped_credential_creates_and_uses_compatible_profile(): void
    {
        [$user, $team] = $this->member('owner');
        $identifier = $this->identifier($team, Platform::Ios);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::IosCertificate,
        ]);

        $this->actingAs($user)->post(route('accounts.credentials.attachments.store', [$team, $credential]), [
            'app_identifier_id' => $identifier->id,
            'profile_type' => ProfileType::AppStore->value,
        ])->assertRedirect();

        $profile = $identifier->profiles()->where('type', ProfileType::AppStore)->firstOrFail();
        $this->assertTrue($profile->credentials()->whereKey($credential->id)->exists());
    }

    public function test_attachment_rejects_incompatible_platform_and_cross_team_targets(): void
    {
        [$user, $team] = $this->member('owner');
        $android = $this->identifier($team, Platform::Android);
        $foreign = AppIdentifier::factory()->create(['platform' => Platform::Ios]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::IosCertificate,
        ]);
        $url = route('accounts.credentials.attachments.store', [$team, $credential]);

        $this->actingAs($user)->post($url, [
            'app_identifier_id' => $android->id,
            'profile_type' => ProfileType::AppStore->value,
        ])->assertSessionHasErrors('app_identifier_id');

        $this->post($url, [
            'app_identifier_id' => $foreign->id,
            'profile_type' => ProfileType::AppStore->value,
        ])->assertNotFound();
    }

    public function test_viewer_cannot_change_credential_attachments(): void
    {
        [$viewer, $team] = $this->member('viewer');
        $identifier = $this->identifier($team, Platform::Ios);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);

        $this->actingAs($viewer)->post(route('accounts.credentials.attachments.store', [$team, $credential]), [
            'app_identifier_id' => $identifier->id,
        ])->assertForbidden();
    }

    private function member(string $role): array
    {
        $user = User::factory()->create();
        $owner = $role === 'owner' ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);

        return [$user, $team];
    }

    private function identifier(Team $team, Platform $platform): AppIdentifier
    {
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => $platform,
        ]);
    }
}
