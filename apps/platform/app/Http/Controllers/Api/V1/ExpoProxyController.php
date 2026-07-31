<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ExpoProxyController extends Controller
{
    public function graphql(
        Request $request,
        Team $team,
        Integration $integration,
        ExpoClient $expo,
    ): JsonResponse {
        $this->assertConnected($team, $integration);
        $data = $request->validate([
            'query' => ['required', 'string', 'max:1048576'],
            'variables' => ['sometimes', 'array'],
            'operation_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $expo->request(
                $integration,
                $data['query'],
                $data['variables'] ?? [],
                $data['operation_name'] ?? null,
            );
        } catch (ExpoGraphqlException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error' => [
                    'code' => 'expo_upstream_error',
                    'upstream_status' => $exception->status,
                    'details' => $exception->errors,
                ],
            ], $exception->status === 503 ? 503 : 502);
        }

        return response()->json(['data' => $result]);
    }

    public function rest(
        Request $request,
        Team $team,
        Integration $integration,
        string $path,
        ExpoClient $expo,
    ): Response {
        $this->assertConnected($team, $integration);
        $this->validatePath($path);
        $headers = collect([
            'Accept',
            'Content-Type',
            'Idempotency-Key',
            'Expo-Platform',
            'Expo-Version',
        ])->mapWithKeys(function (string $header) use ($request): array {
            $value = $request->header($header);

            return $value === null ? [] : [$header => $value];
        })->all();

        try {
            $upstream = $expo->proxyRest(
                $integration,
                $request->method(),
                $path,
                $request->query(),
                $request->getContent(),
                $headers,
            );
        } catch (ExpoGraphqlException $exception) {
            return response([
                'message' => $exception->getMessage(),
            ], 503);
        }

        $responseHeaders = collect([
            'Content-Type',
            'Retry-After',
            'ETag',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ])->mapWithKeys(function (string $header) use ($upstream): array {
            $value = $upstream->header($header);

            return $value === null ? [] : [$header => $value];
        })->all();

        return response($upstream->body(), $upstream->status(), $responseHeaders);
    }

    private function assertConnected(Team $team, Integration $integration): void
    {
        abort_unless(
            config('integrations.enabled')
            && config('integrations.expo.enabled')
            && $integration->team_id === $team->id
            && $integration->provider === IntegrationProvider::Expo
            && in_array($integration->status, [
                IntegrationStatus::Active,
                IntegrationStatus::Degraded,
            ], true)
            && $integration->oauthCredential()->exists(),
            404,
        );
    }

    private function validatePath(string $path): void
    {
        $decoded = rawurldecode($path);
        $segments = explode('/', str_replace('\\', '/', $decoded));

        if (
            $path === ''
            || strlen($path) > 2048
            || str_contains($decoded, '://')
            || preg_match('/[\x00-\x1F\x7F]/', $decoded)
            || in_array('..', $segments, true)
        ) {
            throw ValidationException::withMessages([
                'path' => 'The Expo proxy path must be a safe relative API path.',
            ]);
        }
    }
}
