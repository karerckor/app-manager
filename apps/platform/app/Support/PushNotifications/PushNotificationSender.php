<?php

namespace App\Support\PushNotifications;

use App\Models\Credential;
use App\Support\Credentials\CredentialFileStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PushNotificationSender
{
    private const EXPO_SEND_URL = 'https://exp.host/--/api/v2/push/send';

    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const FIREBASE_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $notification
     * @return list<array<string, mixed>>
     */
    public function sendExpo(array $recipients, array $notification, ?string $accessToken = null): array
    {
        $messages = array_map(
            fn (string $recipient) => [
                'to' => $recipient,
                ...$notification,
            ],
            $recipients,
        );

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->timeout(20);

            if (filled($accessToken)) {
                $request = $request->withToken(trim($accessToken));
            }

            $response = $request->post(self::EXPO_SEND_URL, $messages);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to the Expo Push Service.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->providerError('Expo Push Service', $response));
        }

        $tickets = $response->json('data');
        if (! is_array($tickets)) {
            throw new RuntimeException('Expo Push Service returned an unexpected response.');
        }

        return collect($recipients)->map(function (string $recipient, int $index) use ($tickets) {
            $ticket = $tickets[$index] ?? null;

            if (! is_array($ticket)) {
                return [
                    'recipient' => $recipient,
                    'status' => 'error',
                    'message' => 'Expo did not return a push ticket for this recipient.',
                ];
            }

            return array_filter([
                'recipient' => $recipient,
                'status' => ($ticket['status'] ?? null) === 'ok' ? 'ok' : 'error',
                'id' => is_string($ticket['id'] ?? null) ? $ticket['id'] : null,
                'message' => is_string($ticket['message'] ?? null) ? $ticket['message'] : null,
                'details' => is_array($ticket['details'] ?? null) ? $ticket['details'] : null,
            ], fn ($value) => $value !== null);
        })->values()->all();
    }

    /**
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function sendFirebase(Credential $credential, array $recipients, array $payload): array
    {
        $serviceAccount = $this->serviceAccount($credential);
        $accessToken = $this->googleAccessToken($serviceAccount);
        $projectId = $serviceAccount['project_id'];
        $endpoint = 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send';
        $message = $payload['message'] ?? null;

        if (! is_array($message) || array_is_list($message)) {
            throw new RuntimeException('The Firebase JSON template must contain a message object.');
        }

        unset($message['token'], $message['topic'], $message['condition']);

        return collect($recipients)->map(function (string $recipient) use ($accessToken, $endpoint, $message) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->withToken($accessToken)
                    ->timeout(20)
                    ->post($endpoint, [
                        'message' => [
                            ...$message,
                            'token' => $recipient,
                        ],
                    ]);
            } catch (ConnectionException) {
                return [
                    'recipient' => $recipient,
                    'status' => 'error',
                    'message' => 'Could not connect to Firebase Cloud Messaging.',
                ];
            }

            $name = $response->json('name');

            return array_filter([
                'recipient' => $recipient,
                'status' => $response->successful() ? 'ok' : 'error',
                'id' => is_string($name) ? $name : null,
                'message' => $response->successful() ? null : $this->responseMessage($response),
                'details' => $response->successful() ? null : $response->json(),
            ], fn ($value) => $value !== null);
        })->values()->all();
    }

    /** @return array{project_id: string, client_email: string, private_key: string} */
    private function serviceAccount(Credential $credential): array
    {
        $media = $credential->getFirstMedia('file');

        if (! $media) {
            throw new RuntimeException('The selected Firebase credential does not have a service-account file.');
        }

        $contents = CredentialFileStorage::contents($media);

        try {
            $serviceAccount = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('The selected Firebase service-account file contains invalid JSON.', previous: $exception);
        }

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (! is_string($serviceAccount[$key] ?? null) || blank($serviceAccount[$key])) {
                throw new RuntimeException("The selected Firebase service account is missing {$key}.");
            }
        }

        if (! preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $serviceAccount['project_id'])) {
            throw new RuntimeException('The selected Firebase service account has an invalid project_id.');
        }

        return $serviceAccount;
    }

    /** @param array{project_id: string, client_email: string, private_key: string} $serviceAccount */
    private function googleAccessToken(array $serviceAccount): string
    {
        $now = time();
        $header = $this->encodeJwtPart(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $this->encodeJwtPart([
            'iss' => $serviceAccount['client_email'],
            'scope' => self::FIREBASE_SCOPE,
            'aud' => self::GOOGLE_TOKEN_URL,
            'iat' => $now - 60,
            'exp' => $now + 3600,
        ]);
        $unsigned = $header.'.'.$claims;

        if (! openssl_sign($unsigned, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Firebase service-account assertion.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post(self::GOOGLE_TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $unsigned.'.'.$this->base64Url($signature),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to Google OAuth.', previous: $exception);
        }

        $accessToken = $response->json('access_token');
        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException($this->providerError('Google OAuth', $response));
        }

        return $accessToken;
    }

    /** @param array<string, mixed> $value */
    private function encodeJwtPart(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function providerError(string $provider, Response $response): string
    {
        return "{$provider} returned HTTP {$response->status()}: ".$this->responseMessage($response);
    }

    private function responseMessage(Response $response): string
    {
        foreach ([
            $response->json('error.message'),
            $response->json('errors.0.message'),
            $response->json('message'),
        ] as $message) {
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        return 'The provider rejected the notification request.';
    }
}
