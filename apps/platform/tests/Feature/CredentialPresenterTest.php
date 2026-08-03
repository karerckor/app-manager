<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\VaultProvider;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\CredentialPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_credential_presents_a_vault_block_and_no_reveal_url(): void
    {
        [$team, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::HashicorpVault, 'secret/data/apns')
            ->create([
                'team_id' => $team->id,
                'type' => CredentialType::ApnsKey,
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ]);

        $presented = CredentialPresenter::present($team, $credential, $identifier, null);

        $this->assertSame('external', $presented['storage_mode']);
        $this->assertSame('hashicorp_vault', $presented['vault']['provider']);
        $this->assertSame('secret/data/apns', $presented['vault']['resource']);
        $this->assertSame('unverified', $presented['vault']['verification']['status']);
        $this->assertNull($presented['file']);
        $this->assertNull($presented['reveal_url'], 'A payload of non-secret fields must not offer a reveal.');
        $this->assertFalse($presented['expo']['available']);
    }

    public function test_local_credential_with_a_password_still_offers_a_reveal_url(): void
    {
        [$team, $identifier] = $this->workspace();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
        ]);

        $presented = CredentialPresenter::present($team, $credential, $identifier, null);

        $this->assertSame('local', $presented['storage_mode']);
        $this->assertNull($presented['vault']);
        $this->assertNotNull($presented['reveal_url']);
    }

    public function test_local_credential_without_password_fields_offers_no_reveal_url(): void
    {
        [$team, $identifier] = $this->workspace();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
        ]);

        $presented = CredentialPresenter::present($team, $credential, $identifier, null);

        $this->assertNull($presented['reveal_url']);
    }

    /** @return array{0: Team, 1: AppIdentifier} */
    private function workspace(): array
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
        ]);

        return [$team, $identifier];
    }
}
