<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PushNotificationToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_lists_only_the_teams_firebase_http_v1_credentials_without_secrets(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$owner, $team] = $this->team();
        $firebase = $this->firebaseCredential($team, 'Atlas Firebase');
        Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
            'name' => 'Apple push key',
        ]);
        $foreign = Team::factory()->create();
        $this->firebaseCredential($foreign, 'Foreign Firebase');

        $this->actingAs($owner)
            ->get(route('accounts.tools.push-notifications.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tools/PushNotifications')
                ->where('canSend', true)
                ->has('firebaseCredentials.data', 1)
                ->where('firebaseCredentials.data.0.id', $firebase->id)
                ->where('firebaseCredentials.data.0.name', 'Atlas Firebase')
                ->where('firebaseCredentials.data.0.ready', true)
                ->where('firebaseCredentials.data.0.file_name', 'service-account.json')
                ->missing('firebaseCredentials.data.0.private_key')
                ->where('firebaseCredentials.total', 1)
                ->where('firebaseCredentials.current_page', 1)
                ->where('firebaseCredentials.last_page', 1)
                ->where('filters.search', '')
                ->where('sendUrl', route('accounts.tools.push-notifications.send', $team)));
    }

    public function test_tool_searches_firebase_credentials_by_name(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$owner, $team] = $this->team();
        $this->firebaseCredential($team, 'Production Firebase');
        $this->firebaseCredential($team, 'Staging Firebase');

        $this->actingAs($owner)
            ->get(route('accounts.tools.push-notifications.index', $team).'?search=Production')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tools/PushNotifications')
                ->has('firebaseCredentials.data', 1)
                ->where('firebaseCredentials.data.0.name', 'Production Firebase')
                ->where('firebaseCredentials.total', 1)
                ->where('filters.search', 'Production'));
    }

    public function test_tool_sends_an_expo_notification_to_multiple_recipients(): void
    {
        [$owner, $team] = $this->team();

        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [
                    ['status' => 'ok', 'id' => 'ticket-one'],
                    [
                        'status' => 'error',
                        'message' => 'Token is not registered.',
                        'details' => ['error' => 'DeviceNotRegistered'],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('accounts.tools.push-notifications.send', $team),
            [
                'provider' => 'expo',
                'recipients' => [
                    'ExponentPushToken[first]',
                    'ExponentPushToken[second]',
                ],
                'expo' => [
                    'access_token' => 'expo-access-token',
                    'title' => 'Build complete',
                    'body' => 'Version 2.4.0 is ready.',
                    'data' => ['build_id' => 'build-42'],
                    'ttl' => 3600,
                    'priority' => 'high',
                    'subtitle' => 'Atlas',
                    'badge' => 2,
                    'sound' => 'default',
                    'channel_id' => 'builds',
                ],
            ],
        );

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson([
                'provider' => 'expo',
                'sent' => 2,
                'successful' => 1,
                'results' => [
                    [
                        'recipient' => 'ExponentPushToken[first]',
                        'status' => 'ok',
                        'id' => 'ticket-one',
                    ],
                    [
                        'recipient' => 'ExponentPushToken[second]',
                        'status' => 'error',
                        'details' => ['error' => 'DeviceNotRegistered'],
                    ],
                ],
            ]);

        Http::assertSent(function (HttpRequest $request) {
            $messages = $request->data();

            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && $request->hasHeader('Authorization', 'Bearer expo-access-token')
                && count($messages) === 2
                && $messages[0]['to'] === 'ExponentPushToken[first]'
                && $messages[1]['to'] === 'ExponentPushToken[second]'
                && $messages[0]['data'] === ['build_id' => 'build-42']
                && $messages[0]['channelId'] === 'builds'
                && $messages[0]['badge'] === 2;
        });
    }

    public function test_tool_uses_the_selected_service_account_and_overrides_firebase_targets(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$owner, $team] = $this->team();
        $credential = $this->firebaseCredential($team, 'Production FCM');

        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response([
                    'access_token' => 'google-access-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ]);
            }

            if (str_starts_with($request->url(), 'https://fcm.googleapis.com/')) {
                return Http::response([
                    'name' => 'projects/atlas-prod-123/messages/'.substr(
                        hash('sha256', $request->data()['message']['token']),
                        0,
                        8,
                    ),
                ]);
            }

            return Http::response(['error' => ['message' => 'Unexpected request.']], 500);
        });

        $response = $this->actingAs($owner)->postJson(
            route('accounts.tools.push-notifications.send', $team),
            [
                'provider' => 'firebase',
                'recipients' => ['fcm-token-one', 'fcm-token-two'],
                'credential_id' => $credential->id,
                'platform' => 'ios',
                'payload' => [
                    'message' => [
                        'token' => 'must-be-replaced',
                        'topic' => 'must-be-removed',
                        'notification' => [
                            'title' => 'Test notification',
                            'body' => 'Sent from App Manager',
                        ],
                        'apns' => [
                            'payload' => [
                                'aps' => ['sound' => 'default', 'badge' => 1],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $response->assertOk()
            ->assertJsonPath('sent', 2)
            ->assertJsonPath('successful', 2)
            ->assertJsonPath('results.0.recipient', 'fcm-token-one')
            ->assertJsonPath('results.1.recipient', 'fcm-token-two');

        Http::assertSent(function (HttpRequest $request) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }

            $assertion = $request->data()['assertion'] ?? '';

            return $request->data()['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && substr_count($assertion, '.') === 2;
        });

        $firebaseRequests = collect(Http::recorded())
            ->map(fn (array $record) => $record[0])
            ->filter(fn (HttpRequest $request) => str_starts_with(
                $request->url(),
                'https://fcm.googleapis.com/v1/projects/atlas-prod-123/messages:send',
            ))
            ->values();

        $this->assertCount(2, $firebaseRequests);
        $this->assertSame(
            ['fcm-token-one', 'fcm-token-two'],
            $firebaseRequests->map(fn (HttpRequest $request) => $request->data()['message']['token'])->all(),
        );

        foreach ($firebaseRequests as $request) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer google-access-token'));
            $this->assertArrayNotHasKey('topic', $request->data()['message']);
            $this->assertSame('Test notification', $request->data()['message']['notification']['title']);
        }
    }

    public function test_viewer_cannot_send_and_an_outsider_cannot_open_the_tool(): void
    {
        [, $team] = $this->team();
        $viewer = User::factory()->create();
        $team->addUser($viewer, 'viewer');
        $outsider = User::factory()->create();

        $this->actingAs($viewer)
            ->get(route('accounts.tools.push-notifications.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canSend', false));

        $this->postJson(route('accounts.tools.push-notifications.send', $team), [
            'provider' => 'expo',
            'recipients' => ['ExponentPushToken[test]'],
            'expo' => ['title' => 'Not allowed'],
        ])->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('accounts.tools.push-notifications.index', $team))
            ->assertForbidden();
    }

    public function test_firebase_credential_must_belong_to_the_current_team(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$owner, $team] = $this->team();
        $foreignTeam = Team::factory()->create();
        $foreignCredential = $this->firebaseCredential($foreignTeam, 'Foreign FCM');

        $this->actingAs($owner)
            ->postJson(route('accounts.tools.push-notifications.send', $team), [
                'provider' => 'firebase',
                'recipients' => ['fcm-token'],
                'credential_id' => $foreignCredential->id,
                'platform' => 'android',
                'payload' => ['message' => ['notification' => ['title' => 'Test']]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential_id');
    }

    private function firebaseCredential(Team $team, string $name): Credential
    {
        [$privateKey] = $this->keyPair();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::FirebaseConfig,
            'name' => $name,
        ]);
        $credential->addMediaFromString(json_encode([
            'type' => 'service_account',
            'project_id' => 'atlas-prod-123',
            'client_email' => 'push@atlas-prod-123.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://attacker.invalid/token',
        ], JSON_THROW_ON_ERROR))
            ->usingFileName('service-account.json')
            ->toMediaCollection('file');

        return $credential->fresh();
    }

    /** @return array{0: string, 1: string} */
    private function keyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);

        return [$privateKey, $details['key']];
    }

    private function team(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);

        return [$owner->fresh(), $team];
    }
}
