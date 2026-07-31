<?php

namespace App\Http\Controllers;

use App\Enums\CredentialType;
use App\Enums\TeamPermission;
use App\Models\Team;
use App\Support\PushNotifications\PushNotificationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PushNotificationToolController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        return Inertia::render('Tools/PushNotifications', [
            'canSend' => $team->allows($request->user(), TeamPermission::ManageCredentials),
            'firebaseCredentials' => $team->credentials()
                ->where('type', CredentialType::FirebaseConfig->value)
                ->with('media')
                ->orderBy('name')
                ->get()
                ->map(function ($credential) {
                    $media = $credential->getFirstMedia('file');

                    return [
                        'id' => $credential->id,
                        'name' => $credential->name,
                        'ready' => $media !== null,
                        'file_name' => $media?->file_name,
                    ];
                })
                ->values(),
            'sendUrl' => route('accounts.tools.push-notifications.send', $team),
            'credentialsUrl' => route('accounts.credentials.index', $team),
        ]);
    }

    public function send(
        Request $request,
        Team $team,
        PushNotificationSender $sender,
    ): JsonResponse {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        abort_unless($team->allows($request->user(), TeamPermission::ManageCredentials), 403);

        $validator = Validator::make($request->all(), [
            'provider' => ['required', Rule::in(['expo', 'firebase'])],
            'recipients' => ['required', 'array', 'min:1', 'max:100'],
            'recipients.*' => ['required', 'string', 'max:4096', 'distinct'],
            'expo' => ['nullable', 'array:access_token,title,body,data,ttl,priority,subtitle,badge,sound,channel_id'],
            'expo.access_token' => ['nullable', 'string', 'max:4096'],
            'expo.title' => ['nullable', 'string', 'max:2048'],
            'expo.body' => ['nullable', 'string', 'max:4096'],
            'expo.data' => ['nullable', 'array'],
            'expo.ttl' => ['nullable', 'integer', 'min:0', 'max:2419200'],
            'expo.priority' => ['nullable', Rule::in(['default', 'normal', 'high'])],
            'expo.subtitle' => ['nullable', 'string', 'max:2048'],
            'expo.badge' => ['nullable', 'integer', 'min:0'],
            'expo.sound' => ['nullable', 'string', 'max:255'],
            'expo.channel_id' => ['nullable', 'string', 'max:255'],
            'credential_id' => [
                Rule::requiredIf(fn () => $request->input('provider') === 'firebase'),
                'nullable',
                'uuid',
                Rule::exists('credentials', 'id')->where(fn ($query) => $query
                    ->where('team_id', $team->id)
                    ->where('type', CredentialType::FirebaseConfig->value)),
            ],
            'platform' => [
                Rule::requiredIf(fn () => $request->input('provider') === 'firebase'),
                'nullable',
                Rule::in(['ios', 'android']),
            ],
            'payload' => [
                Rule::requiredIf(fn () => $request->input('provider') === 'firebase'),
                'nullable',
                'array',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_array($value) && array_is_list($value)) {
                        $fail('The Firebase JSON template must be an object.');
                    }
                },
            ],
            'payload.message' => [
                Rule::requiredIf(fn () => $request->input('provider') === 'firebase'),
                'nullable',
                'array',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_array($value) && array_is_list($value)) {
                        $fail('The Firebase message must be an object.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The notification request is invalid.',
                'errors' => $validator->errors()->toArray(),
            ], 422)->withHeaders($this->privateHeaders());
        }

        $validated = $validator->validated();
        $recipients = collect($validated['recipients'])
            ->map(fn (string $recipient) => trim($recipient))
            ->filter()
            ->unique()
            ->values()
            ->all();

        try {
            $results = $validated['provider'] === 'expo'
                ? $sender->sendExpo(
                    $recipients,
                    $this->expoNotification($validated['expo'] ?? []),
                    $validated['expo']['access_token'] ?? null,
                )
                : $sender->sendFirebase(
                    $team->credentials()
                        ->where('type', CredentialType::FirebaseConfig->value)
                        ->findOrFail($validated['credential_id']),
                    $recipients,
                    $validated['payload'],
                );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502)->withHeaders($this->privateHeaders());
        }

        return response()->json([
            'provider' => $validated['provider'],
            'sent' => count($results),
            'successful' => collect($results)->where('status', 'ok')->count(),
            'results' => $results,
        ])->withHeaders($this->privateHeaders());
    }

    /** @param array<string, mixed> $input */
    private function expoNotification(array $input): array
    {
        $notification = [];

        foreach ([
            'title' => 'title',
            'body' => 'body',
            'priority' => 'priority',
            'subtitle' => 'subtitle',
            'sound' => 'sound',
            'channel_id' => 'channelId',
        ] as $inputKey => $outputKey) {
            if (is_string($input[$inputKey] ?? null) && trim($input[$inputKey]) !== '') {
                $notification[$outputKey] = trim($input[$inputKey]);
            }
        }

        if (is_array($input['data'] ?? null) && $input['data'] !== []) {
            $notification['data'] = $input['data'];
        }

        foreach (['ttl', 'badge'] as $key) {
            if (isset($input[$key])) {
                $notification[$key] = (int) $input[$key];
            }
        }

        return $notification;
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ];
    }
}
