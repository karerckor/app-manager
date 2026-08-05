<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Integrations\Notifications\NotificationDeliveryException;
use App\Integrations\Notifications\NotificationEventCatalog;
use App\Integrations\Notifications\NotificationSender;
use App\Models\Integration;
use App\Models\NotificationDestination;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationDestinationController extends Controller
{
    public function __construct(private readonly NotificationSender $sender) {}

    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $data = $this->validateRequest($request);
        $provider = IntegrationProvider::from($data['provider']);
        $credentials = $this->credentials($data, $provider);

        $destination = DB::transaction(function () use ($team, $request, $data, $provider, $credentials) {
            $integration = Integration::create([
                'team_id' => $team->id,
                'kind' => IntegrationKind::Notification,
                'provider' => $provider,
                'name' => $data['name'],
                'status' => $data['enabled'] ? IntegrationStatus::Active : IntegrationStatus::Disabled,
                'disabled_at' => $data['enabled'] ? null : now(),
                'created_by_user_id' => $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
            ]);

            $destination = NotificationDestination::create([
                'team_id' => $team->id,
                'integration_id' => $integration->id,
                'destination' => $data['destination'],
                'credentials' => $credentials,
                'subscribed_events' => array_values(array_unique($data['events'])),
                'templates' => $data['templates'],
                'tags' => $data['tags'] ?? [],
            ]);

            $integration->update(['external_id' => $destination->id]);

            return $destination;
        });

        return back()->with('status', "Notification destination {$destination->integration->name} created.");
    }

    public function update(
        Request $request,
        Team $team,
        NotificationDestination $notificationDestination,
    ): RedirectResponse {
        logger()->debug('notification destination update received', [
            'name' => $request->input('name'),
            'provider' => $request->input('provider'),
            'destination' => $request->input('destination'),
            'events_count' => count($request->input('events', [])),
            'templates_count' => count($request->input('templates', [])),
            'has_bot_token' => filled($request->input('bot_token')),
        ]);
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->authorizeDestination($team, $notificationDestination);
        try {
            $data = $this->validateRequest($request, $notificationDestination);
        } catch (ValidationException $exception) {
            logger()->debug('notification destination update validation failed', [
                'errors' => $exception->errors(),
            ]);

            throw $exception;
        }
        logger()->debug('notification destination update validated');
        $provider = IntegrationProvider::from($data['provider']);
        $credentials = $this->credentials($data, $provider, $notificationDestination);

        DB::transaction(function () use ($notificationDestination, $request, $data, $provider, $credentials) {
            $notificationDestination->update([
                'destination' => $data['destination'],
                'credentials' => $credentials,
                'subscribed_events' => array_values(array_unique($data['events'])),
                'templates' => $data['templates'],
                'tags' => $data['tags'] ?? [],
            ]);
            $notificationDestination->integration->update([
                'name' => $data['name'],
                'provider' => $provider,
                'status' => $data['enabled'] ? IntegrationStatus::Active : IntegrationStatus::Disabled,
                'disabled_at' => $data['enabled'] ? null : now(),
                'updated_by_user_id' => $request->user()->id,
            ]);
        });

        return back()->with('status', 'Notification destination updated.');
    }

    public function test(
        Request $request,
        Team $team,
        NotificationDestination $notificationDestination,
    ): RedirectResponse {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->authorizeDestination($team, $notificationDestination);
        $notificationDestination->loadMissing('integration');
        $maxLength = $notificationDestination->integration->provider === IntegrationProvider::Discord
            ? 2000
            : 4000;
        $data = $request->validate([
            'event' => ['required', 'string', Rule::in(NotificationEventCatalog::values())],
            'template' => ['required', 'string', "max:{$maxLength}"],
        ]);
        $unknownVariables = NotificationEventCatalog::invalidTemplateVariables(
            $data['event'],
            $data['template'],
        );

        if ($unknownVariables !== []) {
            throw ValidationException::withMessages([
                'test' => 'Unknown template value: {{'.implode('}}, {{', $unknownVariables).'}}.',
            ]);
        }

        $event = NotificationEventCatalog::find($data['event']);
        $message = NotificationEventCatalog::renderExample($data['event'], $data['template'], $notificationDestination->tags ?? []);

        try {
            $this->sender->send($notificationDestination, $message);
        } catch (NotificationDeliveryException $exception) {
            return back()->withErrors(['test' => $exception->getMessage()]);
        }

        $notificationDestination->update(['last_delivered_at' => now()]);

        return back()->with(
            'status',
            "{$event['label']} example sent to {$notificationDestination->destination}.",
        );
    }

    public function destroy(
        Request $request,
        Team $team,
        NotificationDestination $notificationDestination,
    ): RedirectResponse {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->authorizeDestination($team, $notificationDestination);

        DB::transaction(function () use ($notificationDestination, $request) {
            $notificationDestination->integration->update([
                'status' => IntegrationStatus::Disconnected,
                'disconnected_at' => now(),
                'updated_by_user_id' => $request->user()->id,
            ]);
            $notificationDestination->delete();
        });

        return back()->with('status', 'Notification destination removed.');
    }

    /** @return array<string, mixed> */
    private function validateRequest(
        Request $request,
        ?NotificationDestination $destination = null,
    ): array {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', Rule::in($this->providerValues())],
            'destination' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'string', 'max:2048'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'events' => ['present', 'array'],
            'events.*' => ['string', 'distinct', Rule::in(NotificationEventCatalog::values())],
            'templates' => ['required', 'array'],
            'templates.*' => ['required', 'string', 'max:4000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'enabled' => ['required', 'boolean'],
        ]);

        $provider = IntegrationProvider::from($data['provider']);
        $this->validateProviderConfiguration($data, $provider, $destination);

        if ($provider === IntegrationProvider::Discord) {
            foreach ($data['templates'] as $eventKey => $template) {
                if (mb_strlen($template) > 2000) {
                    throw ValidationException::withMessages([
                        "templates.{$eventKey}" => 'Discord messages cannot exceed 2,000 characters.',
                    ]);
                }
            }
        }

        $knownEvents = NotificationEventCatalog::values();
        $unknownTemplates = array_diff(array_keys($data['templates']), $knownEvents);

        if ($unknownTemplates !== []) {
            throw ValidationException::withMessages([
                'templates' => 'Templates contain an unknown notification event.',
            ]);
        }

        foreach ($data['templates'] as $eventKey => $template) {
            $unknownVariables = NotificationEventCatalog::invalidTemplateVariables($eventKey, $template);

            if ($unknownVariables !== []) {
                throw ValidationException::withMessages([
                    "templates.{$eventKey}" => 'Unknown template value: {{'.implode('}}, {{', $unknownVariables).'}}.',
                ]);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateProviderConfiguration(
        array $data,
        IntegrationProvider $provider,
        ?NotificationDestination $destination,
    ): void {
        $field = $provider === IntegrationProvider::Telegram ? 'bot_token' : 'webhook_url';
        $value = trim((string) ($data[$field] ?? ''));
        $hasStoredValue = $destination?->integration?->provider === $provider
            && is_string(data_get($destination->credentials, $field))
            && data_get($destination->credentials, $field) !== '';

        if ($value === '' && ! $hasStoredValue) {
            throw ValidationException::withMessages([
                $field => $provider === IntegrationProvider::Telegram
                    ? 'Enter the Telegram bot token.'
                    : "Enter the {$provider->label()} webhook URL.",
            ]);
        }

        if ($provider === IntegrationProvider::Telegram) {
            if ($value !== '' && ! preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $value)) {
                throw ValidationException::withMessages([
                    'bot_token' => 'Enter a valid Telegram bot token.',
                ]);
            }

            $chat = trim((string) $data['destination']);

            if (! preg_match('/^(?:-?\d+|@[A-Za-z][A-Za-z0-9_]{4,31})$/', $chat)) {
                throw ValidationException::withMessages([
                    'destination' => 'Enter a numeric Telegram chat ID or an @channel username.',
                ]);
            }

            return;
        }

        if ($value === '') {
            return;
        }

        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages([
                'webhook_url' => 'Webhook URLs must use HTTPS and cannot contain credentials.',
            ]);
        }

        $valid = match ($provider) {
            IntegrationProvider::Slack => in_array($host, ['hooks.slack.com', 'hooks.slack-gov.com'], true)
                && str_starts_with($path, '/services/'),
            IntegrationProvider::Discord => in_array($host, ['discord.com', 'discordapp.com'], true)
                && str_contains($path, '/webhooks/'),
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                'webhook_url' => "Enter a valid {$provider->label()} webhook URL.",
            ]);
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function credentials(
        array $data,
        IntegrationProvider $provider,
        ?NotificationDestination $destination = null,
    ): array {
        $field = $provider === IntegrationProvider::Telegram ? 'bot_token' : 'webhook_url';
        $value = trim((string) ($data[$field] ?? ''));

        if ($value === '' && $destination?->integration?->provider === $provider) {
            return [$field => (string) data_get($destination->credentials, $field)];
        }

        return [$field => $value];
    }

    /** @return list<string> */
    private function providerValues(): array
    {
        return [
            IntegrationProvider::Slack->value,
            IntegrationProvider::Discord->value,
            IntegrationProvider::Telegram->value,
        ];
    }

    private function authorizeDestination(Team $team, NotificationDestination $destination): void
    {
        abort_unless(
            $destination->team_id === $team->id
            && $destination->integration?->kind === IntegrationKind::Notification,
            404,
        );
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            config('integrations.enabled') && config('integrations.notifications.enabled'),
            404,
        );
    }
}
