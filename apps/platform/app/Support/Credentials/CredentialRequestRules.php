<?php

namespace App\Support\Credentials;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Team;
use App\Rules\ValidCredentialFile;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The storage-mode half of credential request validation, shared by the web
 * and API controllers. Each controller still assembles its own base rules —
 * their identifier and uniqueness rules genuinely differ — but the gate on
 * the mode and the choice between file rules and vault rules lives here, so
 * the two surfaces cannot be tightened independently.
 */
class CredentialRequestRules
{
    public function __construct(
        private readonly StorageModePolicy $storageModes,
    ) {}

    public function resolveMode(Team $team, Request $request): CredentialStorageMode
    {
        return CredentialStorageMode::tryFrom((string) $request->input('storage_mode'))
            ?? $this->storageModes->defaultFor($team);
    }

    /** @return array<string, mixed> */
    public function forCreate(
        Team $team,
        Request $request,
        CredentialStorageMode $mode,
        ?CredentialType $type,
    ): array {
        $rules = [
            'storage_mode' => ['sometimes', Rule::in($this->storageModes->allowedValuesFor($team))],
        ];

        if ($type) {
            $rules = [
                ...$rules,
                ...($mode === CredentialStorageMode::External
                    ? CredentialSchema::externalRules($type)
                    : CredentialSchema::rules($type, true, (array) $request->input('payload', []))),
            ];
        }

        if ($mode === CredentialStorageMode::Local) {
            $rules['vault'] = ['prohibited'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function forUpdate(
        Credential $credential,
        Request $request,
        ?CredentialType $type,
    ): array {
        if (! $type) {
            return [];
        }

        return $credential->isExternal()
            ? CredentialSchema::externalRules($type)
            : CredentialSchema::rules(
                $type,
                $type !== $credential->type || ! $credential->getFirstMedia('file'),
                (array) $request->input('payload', []),
            );
    }

    /**
     * Rules for the REST API's partial update. Payload, file and vault rules are
     * contributed only for the field the caller actually sent, preserving the
     * pre-BYOV contract: a name-only PATCH validates nothing else, a file-only
     * PATCH does not demand a payload, and a payload-only PATCH does not demand
     * a file.
     *
     * @return array<string, mixed>
     */
    public function forPartialUpdate(Credential $credential, Request $request): array
    {
        $type = $credential->type;

        if ($credential->isExternal()) {
            // The file sub-resource does not exist for an external credential,
            // and its secrets live in the customer's vault, so neither a file
            // nor a password field is ever acceptable here — whichever field
            // the caller happened to send.
            $rules = ['file' => ['prohibited']];

            foreach (CredentialSchema::definition($type)['fields'] as $field) {
                if ($field['input'] === 'password') {
                    $rules['payload.'.$field['name']] = ['prohibited'];
                }
            }

            if ($request->has('vault')) {
                $rules = [...$rules, ...Arr::only(CredentialSchema::externalRules($type), [
                    'vault',
                    'vault.provider',
                    'vault.resource',
                    'vault.metadata',
                    'vault.metadata.*',
                ])];
            }

            return $rules;
        }

        if ($request->has('payload')) {
            return CredentialSchema::rules($type, false, (array) $request->input('payload', []));
        }

        if ($request->hasFile('file')) {
            return [
                'file' => [
                    'file',
                    'max:20480',
                    new ValidCredentialFile($type, $credential->payload ?? []),
                ],
            ];
        }

        return [];
    }

    /** @throws ValidationException */
    public function assertModeIsUnchanged(Credential $credential, Request $request): void
    {
        $requested = CredentialStorageMode::tryFrom((string) $request->input('storage_mode'));

        if ($requested !== null && $requested !== $credential->storage_mode) {
            throw ValidationException::withMessages([
                'storage_mode' => 'Storage mode is fixed when a credential is created. Create a new credential instead.',
            ]);
        }
    }
}
