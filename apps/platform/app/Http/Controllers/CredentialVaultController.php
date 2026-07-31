<?php

namespace App\Http\Controllers;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\TeamPermission;
use App\Models\Credential;
use App\Models\Profile;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CredentialVaultController extends Controller
{
    public function __invoke(Request $request, Team $team): Response
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        $search = trim($request->string('search')->toString());
        $type = CredentialType::tryFrom($request->string('type')->toString());
        $statuses = ['valid', 'expiring', 'expired', 'never', 'unattached', 'missing_file'];
        $status = in_array($request->string('status')->toString(), $statuses, true)
            ? $request->string('status')->toString()
            : '';
        $sort = in_array($request->string('sort')->toString(), ['updated', 'expiry'], true)
            ? $request->string('sort')->toString()
            : 'updated';
        $requestedAppId = trim($request->string('app')->toString());
        $appId = Str::isUuid($requestedAppId)
            ? $team->mobileApps()->whereKey($requestedAppId)->value('id')
            : null;
        $today = now()->startOfDay();
        $warningDate = $today->copy()->addDays(30)->endOfDay();
        $canManage = $team->allows($request->user(), TeamPermission::ManageCredentials);

        $credentials = $team->credentials()
            ->with([
                'media',
                'appIdentifiers.mobileApp',
                'profiles.appIdentifier.mobileApp',
            ])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                $pattern = "%{$search}%";

                $query->whereLike('name', $pattern)
                    ->orWhereHas('appIdentifiers', fn (Builder $query) => $query
                        ->whereLike('identifier', $pattern)
                        ->orWhereLike('name', $pattern)
                        ->orWhereHas('mobileApp', fn (Builder $query) => $query->whereLike('name', $pattern)))
                    ->orWhereHas('profiles.appIdentifier', fn (Builder $query) => $query
                        ->whereLike('identifier', $pattern)
                        ->orWhereLike('name', $pattern)
                        ->orWhereHas('mobileApp', fn (Builder $query) => $query->whereLike('name', $pattern)));
            }))
            ->when($type, fn (Builder $query) => $query->where('type', $type->value))
            ->when($status === 'valid', fn (Builder $query) => $query->where('expires_at', '>', $warningDate))
            ->when($status === 'expiring', fn (Builder $query) => $query
                ->whereBetween('expires_at', [$today, $warningDate]))
            ->when($status === 'expired', fn (Builder $query) => $query->where('expires_at', '<', $today))
            ->when($status === 'never', fn (Builder $query) => $query->whereNull('expires_at'))
            ->when($status === 'unattached', fn (Builder $query) => $query
                ->whereDoesntHave('appIdentifiers')
                ->whereDoesntHave('profiles'))
            ->when($status === 'missing_file', fn (Builder $query) => $query
                ->whereDoesntHave('media', fn (Builder $query) => $query
                    ->where('collection_name', 'file')))
            ->when($appId, fn (Builder $query) => $query->where(function (Builder $query) use ($appId) {
                $query->whereHas('appIdentifiers', fn (Builder $query) => $query->where('mobile_app_id', $appId))
                    ->orWhereHas('profiles.appIdentifier', fn (Builder $query) => $query->where('mobile_app_id', $appId));
            }))
            ->when(
                $sort === 'expiry',
                fn (Builder $query) => $query->orderByRaw('expires_at IS NULL ASC, expires_at ASC'),
                fn (Builder $query) => $query->latest('updated_at'),
            )
            ->paginate(18)
            ->withQueryString()
            ->through(fn (Credential $credential) => $this->presentCredential(
                $team,
                $credential,
                $today,
                $canManage,
            ));

        $apps = $team->mobileApps()
            ->with(['appIdentifiers' => fn ($query) => $query
                ->whereIn('platform', [Platform::Ios->value, Platform::Android->value])
                ->orderBy('platform')
                ->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn ($app) => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'store_url' => route('accounts.apps.credentials.store', [$team, $app]),
                'identifiers' => $app->appIdentifiers->map(fn ($identifier) => [
                    'id' => $identifier->id,
                    'name' => $identifier->name,
                    'identifier' => $identifier->identifier,
                    'platform' => $identifier->platform->value,
                ])->values(),
            ])->values();

        return Inertia::render('Credentials/Index', [
            'credentials' => $credentials,
            'credentialTypes' => CredentialSchema::definitions(),
            'buildProfiles' => BuildProfileSchema::definitions(),
            'apps' => $apps,
            'canManage' => $canManage,
            'checkUniquenessByDefault' => $team->check_credential_uniqueness_by_default,
            'filters' => [
                'search' => $search,
                'type' => $type?->value ?? '',
                'status' => $status,
                'app' => $appId ?? '',
                'sort' => $sort,
            ],
            'stats' => [
                'total' => $team->credentials()->count(),
                'expiring' => $team->credentials()
                    ->whereBetween('expires_at', [$today, $warningDate])
                    ->count(),
                'expired' => $team->credentials()
                    ->where('expires_at', '<', $today)
                    ->count(),
                'missing_files' => $team->credentials()
                    ->whereDoesntHave('media', fn (Builder $query) => $query->where('collection_name', 'file'))
                    ->count(),
                'unattached' => $team->credentials()
                    ->whereDoesntHave('appIdentifiers')
                    ->whereDoesntHave('profiles')
                    ->count(),
                'never' => $team->credentials()->whereNull('expires_at')->count(),
            ],
            'tagSuggestions' => $team->credentials()
                ->get(['tags'])
                ->pluck('tags')
                ->filter()
                ->flatten(1)
                ->filter(fn ($tag) => filled($tag[0] ?? null))
                ->unique(fn ($tag) => mb_strtolower($tag[0]))
                ->values(),
        ]);
    }

    private function presentCredential(
        Team $team,
        Credential $credential,
        Carbon $today,
        bool $canManage,
    ): array {
        $definition = CredentialSchema::definition($credential->type);
        $payload = $credential->payload ?? [];
        $media = $credential->getFirstMedia('file');
        $passwordFieldsCount = collect($definition['fields'])
            ->filter(fn (array $field) => $field['input'] === 'password'
                && array_key_exists($field['name'], $payload))
            ->count();

        $identifierAttachments = $credential->appIdentifiers->map(fn ($identifier) => [
            'app_id' => $identifier->mobileApp->id,
            'app_name' => $identifier->mobileApp->name,
            'app_identifier_id' => $identifier->id,
            'identifier_name' => $identifier->name,
            'identifier' => $identifier->identifier,
            'platform' => $identifier->platform->value,
            'profile' => null,
            'url' => route('accounts.apps.identifiers.show', [$team, $identifier->mobileApp, $identifier]),
        ]);

        $profileAttachments = $credential->profiles->map(fn (Profile $profile) => [
            'app_id' => $profile->appIdentifier->mobileApp->id,
            'app_name' => $profile->appIdentifier->mobileApp->name,
            'app_identifier_id' => $profile->appIdentifier->id,
            'identifier_name' => $profile->appIdentifier->name,
            'identifier' => $profile->appIdentifier->identifier,
            'platform' => $profile->appIdentifier->platform->value,
            'profile' => BuildProfileSchema::definition($profile->type, $profile->appIdentifier->platform),
            'url' => route('accounts.apps.identifiers.show', [
                $team,
                $profile->appIdentifier->mobileApp,
                $profile->appIdentifier,
            ]),
        ]);

        $attachments = $identifierAttachments
            ->concat($profileAttachments)
            ->sortBy([['app_name', 'asc'], ['identifier', 'asc']])
            ->values();
        $expiration = $this->expiration($credential, $today);

        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'type' => $credential->type->value,
            'tags' => $credential->tags ?? [],
            'fields' => array_keys($payload),
            'configured_fields' => array_keys($payload),
            'password_fields_count' => $passwordFieldsCount,
            'payload' => collect($definition['fields'])->mapWithKeys(fn ($field) => [
                $field['name'] => $field['input'] === 'password'
                    ? ''
                    : ($payload[$field['name']] ?? ''),
            ]),
            'expires_at' => $credential->expires_at,
            'expiration' => $expiration,
            'updated_at' => $credential->updated_at,
            'attachments' => $attachments,
            'update_url' => $canManage ? route('accounts.credentials.update', [$team, $credential]) : null,
            'delete_url' => $canManage ? route('accounts.credentials.destroy', [$team, $credential]) : null,
            'upload_url' => $canManage ? route('accounts.credentials.upload', [$team, $credential]) : null,
            'tags_url' => $canManage ? route('accounts.credentials.tags.update', [$team, $credential]) : null,
            'reveal_url' => $canManage && $passwordFieldsCount > 0
                ? route('accounts.credentials.reveal', [$team, $credential])
                : null,
            'file' => $media ? [
                'name' => $media->file_name,
                'size' => $media->size,
                'download_url' => $canManage
                    ? route('accounts.credentials.download', [$team, $credential])
                    : null,
            ] : null,
        ];
    }

    private function expiration(Credential $credential, Carbon $today): array
    {
        if (! $credential->expires_at) {
            return ['status' => 'never', 'days' => null];
        }

        $days = (int) $today->diffInDays($credential->expires_at->copy()->startOfDay(), false);

        return [
            'status' => $days < 0 ? 'expired' : ($days <= 30 ? 'expiring' : 'valid'),
            'days' => $days,
        ];
    }
}
