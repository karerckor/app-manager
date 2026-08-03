<script>
    import { useForm } from '@inertiajs/svelte';
    import { Database, Plus, Upload } from '@lucide/svelte';
    import Select from '../../../Components/Select.svelte';
    import VaultReferenceFields from '../../../Components/VaultReferenceFields.svelte';

    let {
        app,
        identifiers = [],
        credentialTypes = [],
        availableCredentials = [],
        checkUniquenessByDefault = true,
        vaultProviders = [],
        allowedStorageModes = ['local'],
        reuseUrl = null,
        editing = null,
        open,
        onClose,
        onSaved,
    } = $props();

    const payloadFor = (type) => Object.fromEntries((type?.fields ?? []).map((field) => [field.name, '']));
    const profilesFor = (type, platform) => (type?.profiles ?? []).filter((profile) => profile.platform === platform);
    const defaultVault = () => ({ provider: vaultProviders[0]?.value ?? 'custom', resource: '', metadata: {} });

    const initialIdentifier = identifiers[0] ?? null;
    const initialType = credentialTypes.find((type) => type.platforms.includes(initialIdentifier?.platform)) ?? credentialTypes[0];
    const initialProfile = profilesFor(initialType, initialIdentifier?.platform)[0];
    const credentialForm = useForm({
        mobile_app_id: app.id,
        app_identifier_id: initialIdentifier?.id ?? '',
        name: '',
        type: initialType?.value ?? '',
        profile_type: initialProfile?.value ?? '',
        expires_at: '',
        payload: payloadFor(initialType),
        file: null,
        check_uniqueness: checkUniquenessByDefault,
        storage_mode: allowedStorageModes[0],
        vault: defaultVault(),
    });
    let credentialTypeChanged = $state(false);
    const reuseForm = useForm({
        credential_id: '',
        profile_type: '',
    });
    let addMode = $state('existing');

    const selectedIdentifier = $derived(identifiers.find((identifier) => String(identifier.id) === String(credentialForm.app_identifier_id)));
    const availableCredentialTypes = $derived(credentialTypes.filter((type) => type.platforms.includes(selectedIdentifier?.platform)));
    const selectedSchema = $derived(credentialTypes.find((type) => type.value === credentialForm.type));
    const availableProfiles = $derived(profilesFor(selectedSchema, selectedIdentifier?.platform));
    const selectedProfile = $derived(availableProfiles.find((profile) => profile.value === credentialForm.profile_type));
    const credentialFileRequired = () => !editing || credentialTypeChanged || !editing.file;
    const selectedVaultCredential = $derived(availableCredentials.find(
        (credential) => String(credential.id) === String(reuseForm.credential_id),
    ));
    const selectedVaultSchema = $derived(credentialTypes.find((type) => type.value === selectedVaultCredential?.type));
    const availableVaultProfiles = $derived(profilesFor(selectedVaultSchema, selectedIdentifier?.platform)
        .filter((profile) => !selectedVaultCredential?.attached_profile_types.includes(profile.value)));
    const isExternal = $derived(credentialForm.storage_mode === 'external');
    const visiblePayloadFields = $derived((selectedSchema?.fields ?? []).filter((field) => !isExternal || field.input !== 'password'));

    const schemaFor = (type) => credentialTypes.find((definition) => definition.value === type);
    const typeLabel = (type) => schemaFor(type)?.label ?? type.replaceAll('_', ' ');
    const selectVaultCredential = (value) => {
        const credential = availableCredentials.find((item) => String(item.id) === String(value));
        const schema = schemaFor(credential?.type);
        const profiles = profilesFor(schema, identifiers[0]?.platform)
            .filter((profile) => !credential?.attached_profile_types.includes(profile.value));

        reuseForm.credential_id = credential?.id ?? '';
        reuseForm.profile_type = profiles[0]?.value ?? '';
        reuseForm.clearErrors();
    };
    const changeVaultCredential = (event) => selectVaultCredential(event.detail);
    const changeAddMode = (mode) => {
        addMode = mode;
        credentialForm.clearErrors();
        reuseForm.clearErrors();
    };

    const openCreateCredential = () => {
        const identifier = identifiers[0] ?? null;
        const schema = credentialTypes.find((type) => type.platforms.includes(identifier?.platform)) ?? credentialTypes[0];
        addMode = 'existing';
        credentialForm.mobile_app_id = app.id;
        credentialForm.app_identifier_id = identifier?.id ?? '';
        credentialForm.name = '';
        credentialForm.expires_at = '';
        credentialForm.type = schema?.value ?? '';
        credentialForm.profile_type = profilesFor(schema, identifier?.platform)[0]?.value ?? '';
        credentialForm.payload = payloadFor(schema);
        credentialForm.file = null;
        credentialForm.check_uniqueness = checkUniquenessByDefault;
        credentialForm.storage_mode = allowedStorageModes[0];
        credentialForm.vault = defaultVault();
        credentialTypeChanged = false;
        credentialForm.clearErrors();
        selectVaultCredential(availableCredentials[0]?.id ?? '');
    };
    const openEditCredential = (credential) => {
        const schema = schemaFor(credential.type);
        credentialForm.mobile_app_id = app.id;
        credentialForm.app_identifier_id = credential.app_identifier_id;
        credentialForm.name = credential.name;
        credentialForm.type = credential.type;
        credentialForm.profile_type = credential.profile?.value ?? '';
        credentialForm.expires_at = credential.expires_at?.slice(0, 10) ?? '';
        credentialForm.payload = { ...payloadFor(schema), ...credential.payload };
        credentialForm.file = null;
        credentialForm.check_uniqueness = checkUniquenessByDefault;
        credentialForm.storage_mode = credential.storage_mode;
        // The server replaces vault metadata wholesale on update, so the editor
        // must always start from what the pipeline last discovered. Submitting
        // a rename with an empty metadata editor would otherwise erase it.
        credentialForm.vault = credential.vault
            ? { provider: credential.vault.provider, resource: credential.vault.resource, metadata: { ...(credential.vault.metadata ?? {}) } }
            : defaultVault();
        credentialTypeChanged = false;
        credentialForm.clearErrors();
    };

    let wasOpen = false;
    // $effect.pre (not $effect) so the reset lands before this same open-toggle
    // renders the modal's children — VaultReferenceFields seeds its key/value
    // rows once, at mount, from credentialForm.vault.metadata, so the reset
    // must happen before that mount, not after it.
    $effect.pre(() => {
        if (open !== wasOpen) {
            wasOpen = open;

            if (open) {
                if (editing) {
                    openEditCredential(editing);
                } else {
                    openCreateCredential();
                }
            }
        }
    });

    const closeCredential = () => {
        credentialForm.clearErrors();
        reuseForm.clearErrors();
        onClose();
    };
    const saveCredential = () => {
        if (!editing && addMode === 'existing') {
            if (!reuseUrl) return;

            reuseForm.post(reuseUrl, {
                preserveScroll: true,
                onSuccess: () => { onSaved(credentialForm.app_identifier_id); closeCredential(); },
            });

            return;
        }

        credentialForm.post(editing?.update_url ?? app.urls.credentials, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { onSaved(credentialForm.app_identifier_id); closeCredential(); },
        });
    };
    const selectCredentialType = (value, platform = selectedIdentifier?.platform) => {
        const schema = credentialTypes.find((type) => type.value === value);
        const originalProfile = editing?.type === value ? editing.profile?.value : null;
        const profiles = profilesFor(schema, platform);
        credentialForm.type = schema?.value ?? '';
        credentialForm.profile_type = profiles.some((profile) => profile.value === originalProfile)
            ? originalProfile
            : profiles[0]?.value ?? '';
        credentialForm.payload = editing?.type === value
            ? { ...payloadFor(schema), ...editing.payload }
            : payloadFor(schema);
        credentialForm.file = null;
        credentialTypeChanged = Boolean(editing) && String(value) !== String(editing.type);
    };
    const changeCredentialType = (event) => selectCredentialType(event.detail);
    const pickCredentialFile = (event) => credentialForm.file = event.currentTarget.files?.[0] ?? null;
    const credentialFieldRequired = (field) => !editing
        || String(editing.type) !== String(credentialForm.type)
        || field.input !== 'password'
        || !editing.configured_fields.includes(field.name);
    const credentialFieldPlaceholder = (field) => editing
        && String(editing.type) === String(credentialForm.type)
        && field.input === 'password'
        && editing.configured_fields.includes(field.name)
            ? 'Leave blank to keep the current value'
            : field.hint || '';
</script>

{#if open}
    <div class="modal-backdrop" role="presentation" onclick={(event) => event.target === event.currentTarget && closeCredential()}>
        <form class="modal max-w-2xl" onsubmit={(event) => { event.preventDefault(); saveCredential(); }}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div><h2 class="text-lg font-bold">{editing ? 'Edit credential' : 'Add credential'}</h2><p class="mt-1 text-sm text-muted">{editing ? 'Update the shared vault record. Its attachments stay unchanged.' : 'Reuse a credential from the vault or create a new one.'}</p></div>
                <button type="button" class="ghost -mr-2 p-2" onclick={closeCredential}>✕</button>
            </div>
            <div class="space-y-5 p-6">
                <div class="flex items-center gap-2 rounded-md border border-border bg-surface-sunken px-4 py-3">
                    <span class="rounded-sm bg-surface-raised px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-muted uppercase ring-1 ring-border">{selectedIdentifier?.platform}</span>
                    <span class="min-w-0 truncate text-xs text-muted">{editing ? 'Used by' : 'Attaching to'} <code class="font-semibold text-text">{selectedIdentifier?.identifier}</code>{#if editing} · changes apply everywhere this credential is shared{/if}</span>
                    {#if credentialForm.errors.app_identifier_id}<span class="error ml-auto">{credentialForm.errors.app_identifier_id}</span>{/if}
                </div>

                {#if !editing}
                    <div class="grid grid-cols-2 rounded-md bg-surface-sunken p-1">
                        <button
                            type="button"
                            class="flex items-center justify-center gap-2 rounded-sm px-3 py-2 text-xs font-semibold shadow-none {addMode === 'existing' ? 'bg-surface-raised text-brand shadow-sm' : 'bg-transparent text-muted hover:text-text'}"
                            aria-pressed={addMode === 'existing'}
                            onclick={() => changeAddMode('existing')}
                        ><Database class="h-4 w-4" strokeWidth={1.9} /> Existing from Vault</button>
                        <button
                            type="button"
                            class="flex items-center justify-center gap-2 rounded-sm px-3 py-2 text-xs font-semibold shadow-none {addMode === 'new' ? 'bg-surface-raised text-brand shadow-sm' : 'bg-transparent text-muted hover:text-text'}"
                            aria-pressed={addMode === 'new'}
                            onclick={() => changeAddMode('new')}
                        ><Plus class="h-4 w-4" strokeWidth={2} /> Create new</button>
                    </div>
                {/if}

                {#if !editing && addMode === 'existing'}
                    {#if availableCredentials.length}
                        <label>
                            <span class="label">Credential from Credentials Vault</span>
                            <Select bind:value={reuseForm.credential_id} options={availableCredentials.map((credential) => ({ value: credential.id, label: `${credential.name} · ${credential.type_label}` }))} ariaLabel="Credential from Credentials Vault" on:change={changeVaultCredential} required />
                            {#if reuseForm.errors.credential_id}<span class="error">{reuseForm.errors.credential_id}</span>{/if}
                        </label>

                        {#if selectedVaultCredential}
                            <div class="rounded-md border border-brand-soft-line bg-brand-soft px-4 py-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-xs font-semibold text-text">{selectedVaultCredential.name}</p>
                                        <p class="mt-0.5 text-[10px] text-muted">{typeLabel(selectedVaultCredential.type)}</p>
                                    </div>
                                    <span class="rounded-full bg-surface-raised px-2 py-1 text-[10px] font-semibold text-brand ring-1 ring-brand-soft-line">
                                        {selectedVaultCredential.attachments_count === 0 ? 'Not used yet' : `Used in ${selectedVaultCredential.attachments_count} place${selectedVaultCredential.attachments_count === 1 ? '' : 's'}`}
                                    </span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-muted">
                                    <span>{selectedVaultCredential.file_name ? `File: ${selectedVaultCredential.file_name}` : 'No file uploaded'}</span>
                                    <span>{selectedVaultCredential.expires_at ? `Expires: ${selectedVaultCredential.expires_at.slice(0, 10)}` : 'No expiration'}</span>
                                </div>
                                <p class="mt-2 text-[10px] font-medium text-brand">The same vault record, encrypted fields, and file will be reused. No credential copy is created.</p>
                            </div>
                        {/if}

                        {#if selectedVaultSchema?.attachment === 'profile'}
                            <fieldset>
                                <legend class="label">Build target</legend>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    {#each availableVaultProfiles as profile}
                                        <label class="cursor-pointer rounded-md border p-3.5 transition {reuseForm.profile_type === profile.value ? 'border-brand bg-brand-soft ring-2 ring-brand-soft-line' : 'border-border bg-surface-raised hover:border-border-control'}">
                                            <input class="sr-only" type="radio" name="reuse_profile_type" value={profile.value} bind:group={reuseForm.profile_type} required />
                                            <span class="flex items-center justify-between gap-3">
                                                <strong class="text-xs text-text">{profile.label}</strong>
                                                <span class="rounded-sm bg-surface-raised px-2 py-0.5 font-mono text-[10px] font-bold text-brand shadow-sm ring-1 ring-border">{profile.artifact}</span>
                                            </span>
                                            <span class="mt-1.5 block text-[10px] leading-4 text-muted">{profile.description}</span>
                                        </label>
                                    {/each}
                                </div>
                                {#if reuseForm.errors.profile_type}<span class="error">{reuseForm.errors.profile_type}</span>{/if}
                            </fieldset>
                        {/if}
                    {:else}
                        <div class="rounded-md border border-dashed border-control bg-surface-sunken px-5 py-6 text-center">
                            <Database class="mx-auto h-6 w-6 text-faint" strokeWidth={1.7} />
                            <p class="mt-2 text-sm font-semibold text-text">No compatible credentials available</p>
                            <p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-muted">The vault has no credential that can be newly attached to this {selectedIdentifier?.platform?.toUpperCase()} identifier.</p>
                            <button type="button" class="secondary mt-4 inline-flex items-center gap-1.5 text-xs" onclick={() => changeAddMode('new')}><Plus class="h-4 w-4" /> Create a new credential</button>
                        </div>
                    {/if}
                {:else}
                    {#if allowedStorageModes.length > 1 && !editing}
                        <div class="inline-flex rounded-md border border-border p-0.5" role="radiogroup" aria-label="Storage mode">
                            {#each allowedStorageModes as mode (mode)}
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={credentialForm.storage_mode === mode}
                                    class="bg-transparent px-3 py-1.5 text-xs font-semibold shadow-none {credentialForm.storage_mode === mode ? 'rounded-sm bg-brand-soft text-brand' : 'text-muted'}"
                                    onclick={() => (credentialForm.storage_mode = mode)}
                                >
                                    {mode === 'local' ? 'Store in App Manager' : 'Reference my vault'}
                                </button>
                            {/each}
                        </div>
                    {:else if editing}
                        <p class="text-[11px] text-faint">
                            Storage mode is fixed when a credential is created. To change it, create a new credential and move its attachments.
                        </p>
                    {/if}

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label>
                            <span class="label">Credential type</span>
                            <Select bind:value={credentialForm.type} options={availableCredentialTypes.map((type) => ({ value: type.value, label: type.label }))} ariaLabel="Credential type" on:change={changeCredentialType} disabled={editing} />
                            {#if editing}<span class="mt-1 block text-[10px] text-faint">Detach it from all targets in Credentials Vault before changing its type.</span>{/if}
                            {#if credentialForm.errors.type}<span class="error">{credentialForm.errors.type}</span>{/if}
                        </label>
                        <label>
                            <span class="label">Expiration <span class="font-normal text-faint">(optional)</span></span>
                            <input type="date" bind:value={credentialForm.expires_at} />
                            {#if credentialForm.errors.expires_at}<span class="error">{credentialForm.errors.expires_at}</span>{/if}
                        </label>
                    </div>
                    {#if !editing && selectedSchema?.attachment === 'profile'}
                        <fieldset>
                            <legend class="label">Build target</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                {#each availableProfiles as profile}
                                    <label class="cursor-pointer rounded-md border p-3.5 transition {credentialForm.profile_type === profile.value ? 'border-brand bg-brand-soft ring-2 ring-brand-soft-line' : 'border-border bg-surface-raised hover:border-border-control'}">
                                        <input class="sr-only" type="radio" name="profile_type" value={profile.value} bind:group={credentialForm.profile_type} required />
                                        <span class="flex items-center justify-between gap-3">
                                        <strong class="text-xs text-text">{profile.label}</strong>
                                        <span class="rounded-sm bg-surface-raised px-2 py-0.5 font-mono text-[10px] font-bold text-brand shadow-sm ring-1 ring-border">{profile.artifact}</span>
                                        </span>
                                    <span class="mt-1.5 block text-[10px] leading-4 text-muted">{profile.description}</span>
                                    </label>
                                {/each}
                            </div>
                            {#if credentialForm.errors.profile_type}<span class="error">{credentialForm.errors.profile_type}</span>{/if}
                        </fieldset>
                    {/if}
                    <label>
                        <span class="label">Name</span>
                        <input bind:value={credentialForm.name} placeholder={selectedProfile ? `${selectedProfile.label} signing credential` : 'Credential name'} required />
                        {#if credentialForm.errors.name}<span class="error">{credentialForm.errors.name}</span>{/if}
                    </label>

                    {#if visiblePayloadFields.length}
                        <div class="grid gap-4 sm:grid-cols-2">
                            {#each visiblePayloadFields as field (field.name)}
                                <label class={visiblePayloadFields.length % 2 === 1 && field === visiblePayloadFields.at(-1) ? 'sm:col-span-2' : ''}>
                                    <span class="label">{field.label}</span>
                                    <input
                                        type={field.input}
                                        bind:value={credentialForm.payload[field.name]}
                                        placeholder={credentialFieldPlaceholder(field)}
                                        autocomplete="off"
                                        required={field.required && credentialFieldRequired(field)}
                                    />
                                    {#if editing && field.input === 'password' && !credentialFieldRequired(field)}<span class="mt-1 block text-[10px] text-faint">Already configured. Enter a new value only to replace it.</span>{/if}
                                    {#if field.hint}<span class="mt-1 block text-[10px] text-faint">{field.hint}</span>{/if}
                                    {#if credentialForm.errors[`payload.${field.name}`]}<span class="error">{credentialForm.errors[`payload.${field.name}`]}</span>{/if}
                                </label>
                            {/each}
                        </div>
                    {/if}

                    {#if isExternal}
                        <VaultReferenceFields form={credentialForm} providers={vaultProviders} />
                    {:else}
                        <label class="block">
                            <span class="label">Credential file <span class="font-normal text-faint">({credentialFileRequired() ? 'required' : 'optional'}, max 20 MB)</span></span>
                            <span class="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-control bg-surface-sunken px-4 py-4 transition hover:border-brand hover:bg-brand-soft">
                                <span class="grid h-9 w-9 place-items-center rounded-sm bg-surface-raised text-brand shadow-sm"><Upload class="h-4.5 w-4.5" strokeWidth={1.8} /></span>
                                <span class="min-w-0"><strong class="block truncate text-xs text-text">{credentialForm.file?.name || (editing?.file && !credentialTypeChanged ? `Keep ${editing.file.name}` : 'Choose a file')}</strong><small class="text-[10px] text-faint">{credentialTypeChanged ? 'Changing the type requires a matching replacement file.' : selectedSchema?.file.hint || 'Credential file'}</small></span>
                                <input type="file" class="hidden" accept={selectedSchema?.file.accept} onchange={pickCredentialFile} />
                            </span>
                            {#if credentialForm.errors.file}<span class="error">{credentialForm.errors.file}</span>{/if}
                        </label>
                    {/if}

                    {#if !editing}
                        <label class="flex cursor-pointer items-start justify-between gap-4 rounded-md border border-border bg-surface-sunken px-4 py-3.5">
                            <span>
                                <span class="block text-xs font-semibold text-text">Check uniqueness</span>
                                <span class="mt-1 block text-[11px] leading-4 text-muted">Reuse an identical credential from this team vault instead of storing a duplicate database record and file.</span>
                            </span>
                            <span class="relative mt-0.5 shrink-0">
                                <input class="sr-only" type="checkbox" role="switch" bind:checked={credentialForm.check_uniqueness} />
                                <span class="block h-5 w-9 rounded-full transition {credentialForm.check_uniqueness ? 'bg-brand' : 'bg-border-control'}">
                                    <span class="block h-4 w-4 translate-y-0.5 rounded-full bg-surface-raised shadow-sm transition {credentialForm.check_uniqueness ? 'translate-x-[18px]' : 'translate-x-0.5'}"></span>
                                </span>
                            </span>
                        </label>
                    {/if}
                {/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4">
                <button type="button" class="secondary" onclick={closeCredential}>Cancel</button>
                <button disabled={(addMode === 'existing' && !editing ? reuseForm.processing || !availableCredentials.length : credentialForm.processing)}>
                    {addMode === 'existing' && !editing
                        ? (reuseForm.processing ? 'Reusing…' : 'Reuse credential')
                        : (credentialForm.processing ? 'Saving…' : editing ? 'Save changes' : 'Create credential')}
                </button>
            </div>
        </form>
    </div>
{/if}
