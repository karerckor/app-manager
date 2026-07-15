<script>
    import { useForm } from '@inertiajs/svelte';
    import { ShieldCheck, Upload } from '@lucide/svelte';
    import Select from '../../Components/Select.svelte';
    import VaultReferenceFields from '../../Components/VaultReferenceFields.svelte';

    let {
        apps = [],
        credentialTypes = [],
        vaultProviders = [],
        allowedStorageModes = ['local'],
        checkUniquenessByDefault = true,
        editing = null,
        open,
        onClose,
        onSaved,
    } = $props();

    const payloadFor = (type) => Object.fromEntries((type?.fields ?? []).map((field) => [field.name, '']));
    const profilesFor = (type, platform) => (type?.profiles ?? []).filter((profile) => profile.platform === platform);
    const firstConfiguredApp = () => apps.find((app) => app.identifiers.length) ?? apps[0] ?? null;
    const defaultVault = () => ({ provider: vaultProviders[0]?.value ?? 'custom', resource: '', metadata: {} });

    const initialApp = firstConfiguredApp();
    const initialIdentifier = initialApp?.identifiers[0] ?? null;
    const initialType = credentialTypes.find((type) => type.platforms.includes(initialIdentifier?.platform)) ?? credentialTypes[0];
    const form = useForm({
        mobile_app_id: initialApp?.id ?? '',
        app_identifier_id: initialIdentifier?.id ?? '',
        name: '',
        type: initialType?.value ?? '',
        profile_type: profilesFor(initialType, initialIdentifier?.platform)[0]?.value ?? '',
        expires_at: '',
        payload: payloadFor(initialType),
        file: null,
        check_uniqueness: checkUniquenessByDefault,
        storage_mode: allowedStorageModes[0],
        vault: defaultVault(),
    });
    let credentialTypeChanged = $state(false);

    const selectedApp = $derived(apps.find((app) => String(app.id) === String(form.mobile_app_id)));
    const selectedIdentifier = $derived(selectedApp?.identifiers.find((identifier) => String(identifier.id) === String(form.app_identifier_id)));
    const availableCredentialTypes = $derived(editing
        ? credentialTypes
        : credentialTypes.filter((type) => type.platforms.includes(selectedIdentifier?.platform)));
    const selectedSchema = $derived(credentialTypes.find((type) => type.value === form.type));
    const availableProfiles = $derived(profilesFor(selectedSchema, selectedIdentifier?.platform));
    const typeLocked = $derived(Boolean(editing?.attachments?.length));
    const isExternal = $derived(form.storage_mode === 'external');
    const visiblePayloadFields = $derived((selectedSchema?.fields ?? []).filter((field) => !isExternal || field.input !== 'password'));
    const credentialFileRequired = () => !editing || credentialTypeChanged || !editing.file;

    // Rendering hides the vault block in local mode and the password fields
    // in external mode, but the form's internal state still carries all of
    // it — payloadFor() seeds every field name, and vault defaults to a
    // non-empty object regardless of mode. Posted straight through, local
    // mode's non-empty `vault` trips the server's `prohibited` rule, and
    // external mode's password payload keys trip its payload shape rule.
    // Shape the outgoing data instead of the rendered data.
    const shapeForSubmit = (data) => {
        if (data.storage_mode !== 'external') {
            const { vault, ...rest } = data;
            return rest;
        }

        const visibleNames = new Set(visiblePayloadFields.map((field) => field.name));

        return {
            ...data,
            payload: Object.fromEntries(
                Object.entries(data.payload ?? {}).filter(([key]) => visibleNames.has(key)),
            ),
        };
    };

    const displayedErrorKeys = $derived(new Set([
        'mobile_app_id',
        'app_identifier_id',
        'type',
        'expires_at',
        'profile_type',
        'name',
        'file',
        'vault.provider',
        'vault.resource',
        ...visiblePayloadFields.map((field) => `payload.${field.name}`),
    ]));
    const otherErrors = $derived(Object.entries(form.errors).filter(([key]) => !displayedErrorKeys.has(key)));

    const resetForCreate = () => {
        const app = firstConfiguredApp();
        const identifier = app?.identifiers[0] ?? null;
        const schema = credentialTypes.find((type) => type.platforms.includes(identifier?.platform)) ?? credentialTypes[0];

        form.mobile_app_id = app?.id ?? '';
        form.app_identifier_id = identifier?.id ?? '';
        form.name = '';
        form.type = schema?.value ?? '';
        form.profile_type = profilesFor(schema, identifier?.platform)[0]?.value ?? '';
        form.expires_at = '';
        form.payload = payloadFor(schema);
        form.file = null;
        form.check_uniqueness = checkUniquenessByDefault;
        form.storage_mode = allowedStorageModes[0];
        form.vault = defaultVault();
        credentialTypeChanged = false;
        form.clearErrors();
    };
    const resetForEdit = () => {
        const schema = credentialTypes.find((type) => type.value === editing.type);

        form.mobile_app_id = '';
        form.app_identifier_id = '';
        form.name = editing.name;
        form.type = editing.type;
        form.profile_type = '';
        form.expires_at = editing.expires_at?.slice(0, 10) ?? '';
        form.payload = { ...payloadFor(schema), ...editing.payload };
        form.file = null;
        form.check_uniqueness = checkUniquenessByDefault;
        form.storage_mode = editing.storage_mode;
        // The server replaces vault metadata wholesale on update, so the editor
        // must always start from what the pipeline last discovered. Submitting
        // a rename with an empty metadata editor would otherwise erase it.
        form.vault = editing.vault
            ? { provider: editing.vault.provider, resource: editing.vault.resource, metadata: { ...(editing.vault.metadata ?? {}) } }
            : defaultVault();
        credentialTypeChanged = false;
        form.clearErrors();
    };

    let vaultFieldsRef = $state(null);
    let wasOpen = false;
    // $effect.pre (not $effect) so the reset lands before this same open-toggle
    // renders the modal's children — VaultReferenceFields seeds its key/value
    // rows once, at mount, from form.vault.metadata, so the reset must happen
    // before that mount, not after it.
    $effect.pre(() => {
        if (open !== wasOpen) {
            wasOpen = open;
            if (open) editing ? resetForEdit() : resetForCreate();
        }
    });

    const setType = (value, platform = selectedIdentifier?.platform) => {
        const schema = credentialTypes.find((type) => type.value === value);
        const originalProfile = !editing && form.type === value ? form.profile_type : null;
        const profiles = profilesFor(schema, platform);

        form.type = schema?.value ?? '';
        form.profile_type = !editing && profiles.some((profile) => profile.value === originalProfile)
            ? originalProfile
            : (!editing ? profiles[0]?.value ?? '' : '');
        form.payload = String(editing?.type) === String(value)
            ? { ...payloadFor(schema), ...editing.payload }
            : payloadFor(schema);
        form.file = null;
        if (!schema?.expires) form.expires_at = '';
        credentialTypeChanged = Boolean(editing) && String(value) !== String(editing.type);
    };
    const changeApp = (event) => {
        form.mobile_app_id = event.detail;
        const app = apps.find((item) => String(item.id) === String(form.mobile_app_id));
        const identifier = app?.identifiers[0] ?? null;
        const schema = credentialTypes.find((type) => type.platforms.includes(identifier?.platform)) ?? credentialTypes[0];
        form.app_identifier_id = identifier?.id ?? '';
        setType(schema?.value ?? '', identifier?.platform);
    };
    const changeIdentifier = (event) => {
        form.app_identifier_id = event.detail;
        const identifier = selectedApp?.identifiers.find((item) => String(item.id) === String(form.app_identifier_id));
        const currentType = credentialTypes.find((type) => type.value === form.type);
        const schema = currentType?.platforms.includes(identifier?.platform)
            ? currentType
            : credentialTypes.find((type) => type.platforms.includes(identifier?.platform));
        setType(schema?.value ?? '', identifier?.platform);
    };
    const close = () => {
        form.clearErrors();
        onClose();
    };
    const save = () => {
        const url = editing?.update_url ?? selectedApp?.store_url;
        if (!url) return;

        vaultFieldsRef?.sync();
        form.transform(shapeForSubmit);
        form.post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                onSaved();
                close();
            },
        });
    };
    const pickFile = (event) => form.file = event.currentTarget.files?.[0] ?? null;
    const fieldRequired = (field) => !editing
        || String(editing.type) !== String(form.type)
        || field.input !== 'password'
        || !editing.configured_fields.includes(field.name);
    const fieldPlaceholder = (field) => editing
        && String(editing.type) === String(form.type)
        && field.input === 'password'
        && editing.configured_fields.includes(field.name)
            ? 'Leave blank to keep the current value'
            : field.hint || '';
</script>

{#if open}
    <div class="modal-backdrop" role="presentation" onclick={(event) => event.target === event.currentTarget && close()}>
        <form class="modal max-w-2xl" onsubmit={(event) => { event.preventDefault(); save(); }}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-brand-soft text-brand">
                        <ShieldCheck class="h-5 w-5" strokeWidth={1.8} />
                    </span>
                    <div>
                        <h2 class="text-lg font-bold">{editing ? 'Edit credential' : 'Add to Credentials Vault'}</h2>
                        <p class="mt-1 text-sm text-muted">{editing ? 'Update the credential, expiration, or secure file.' : 'Store a signing asset and attach it to an application.'}</p>
                    </div>
                </div>
                <button type="button" class="ghost -mr-2 p-2" onclick={close} aria-label="Close">✕</button>
            </div>

            <div class="space-y-5 p-6">
                {#if editing}
                    <div class="rounded-md border border-brand-soft-line bg-brand-soft p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-xs font-bold text-text">Used by</p>
                                <p class="mt-0.5 text-[11px] text-brand">Attachments are preserved when this credential is edited.</p>
                            </div>
                            <span class="rounded-full bg-surface-raised px-2 py-1 text-[10px] font-semibold text-brand ring-1 ring-brand-soft-line">
                                {editing.attachments.length} target{editing.attachments.length === 1 ? '' : 's'}
                            </span>
                        </div>
                        {#if editing.attachments.length}
                            <div class="mt-3 flex flex-wrap gap-2">
                                {#each editing.attachments as attachment}
                                    <a href={attachment.url} class="inline-flex max-w-full items-center gap-1.5 rounded-sm border border-brand-soft-line bg-surface-raised px-2.5 py-1.5 text-[11px] font-medium text-muted shadow-sm transition hover:border-brand hover:text-brand">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full {attachment.platform === 'ios' ? 'bg-text' : 'bg-ok'}"></span>
                                        <span class="truncate">{attachment.app_name}</span>
                                        <span class="text-border">/</span>
                                        <code class="truncate text-[10px]">{attachment.identifier}</code>
                                        {#if attachment.profile}<span class="rounded-sm bg-brand-soft px-1 py-0.5 text-[9px] font-bold text-brand">{attachment.profile.label}</span>{/if}
                                    </a>
                                {/each}
                            </div>
                            <p class="mt-3 text-[10px] text-brand">Add or remove usage from each app identifier page.</p>
                        {:else}
                            <p class="mt-3 text-[11px] font-medium text-warn-fill">Not attached yet. Add it from an app identifier page.</p>
                        {/if}
                    </div>
                {:else}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label>
                            <span class="label">Application</span>
                            <Select bind:value={form.mobile_app_id} options={apps.map((app) => ({ value: app.id, label: `${app.name}${app.identifiers.length ? '' : ' — no identifiers'}`, disabled: !app.identifiers.length }))} ariaLabel="Application" on:change={changeApp} />
                            {#if form.errors.mobile_app_id}<span class="error">{form.errors.mobile_app_id}</span>{/if}
                        </label>
                        <label>
                            <span class="label">App identifier</span>
                            <Select bind:value={form.app_identifier_id} options={(selectedApp?.identifiers ?? []).map((identifier) => ({ value: identifier.id, label: `${identifier.name || identifier.identifier} · ${identifier.platform.toUpperCase()}` }))} ariaLabel="App identifier" on:change={changeIdentifier} required />
                            {#if form.errors.app_identifier_id}<span class="error">{form.errors.app_identifier_id}</span>{/if}
                        </label>
                    </div>
                {/if}

                {#if allowedStorageModes.length > 1 && !editing}
                    <div class="inline-flex rounded-md border border-border p-0.5" role="radiogroup" aria-label="Storage mode">
                        {#each allowedStorageModes as mode (mode)}
                            <button
                                type="button"
                                role="radio"
                                aria-checked={form.storage_mode === mode}
                                class="bg-transparent px-3 py-1.5 text-xs font-semibold shadow-none {form.storage_mode === mode ? 'rounded-sm bg-brand-soft text-brand' : 'text-muted'}"
                                onclick={() => (form.storage_mode = mode)}
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

                <div class="grid gap-4 {selectedSchema?.expires ? 'sm:grid-cols-2' : ''}">
                    <label>
                        <span class="label">Credential type</span>
                        <Select bind:value={form.type} options={availableCredentialTypes.map((type) => ({ value: type.value, label: type.label }))} ariaLabel="Credential type" on:change={(event) => setType(event.detail)} disabled={typeLocked} />
                        {#if typeLocked}<span class="mt-1 block text-[10px] text-faint">Detach this credential from all targets before changing its type.</span>{/if}
                        {#if form.errors.type}<span class="error">{form.errors.type}</span>{/if}
                    </label>
                    {#if selectedSchema?.expires}
                        <label>
                            <span class="label">Expiration <span class="font-normal text-faint">(optional)</span></span>
                            <input type="date" bind:value={form.expires_at} />
                            {#if form.errors.expires_at}<span class="error">{form.errors.expires_at}</span>{/if}
                        </label>
                    {/if}
                </div>

                {#if !editing && selectedSchema?.attachment === 'profile'}
                    <fieldset>
                        <legend class="label">Build target</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            {#each availableProfiles as profile}
                                <label class="cursor-pointer rounded-md border p-3.5 transition {form.profile_type === profile.value ? 'border-brand bg-brand-soft ring-2 ring-brand-soft-line' : 'border-border bg-surface-raised hover:border-border-control'}">
                                    <input class="sr-only" type="radio" name="profile_type" value={profile.value} bind:group={form.profile_type} required />
                                    <span class="flex items-center justify-between gap-3">
                                        <strong class="text-xs text-text">{profile.label}</strong>
                                        <span class="rounded-sm bg-surface-raised px-2 py-0.5 font-mono text-[10px] font-bold text-brand shadow-sm ring-1 ring-border">{profile.artifact}</span>
                                    </span>
                                    <span class="mt-1.5 block text-[10px] leading-4 text-muted">{profile.description}</span>
                                </label>
                            {/each}
                        </div>
                        {#if form.errors.profile_type}<span class="error">{form.errors.profile_type}</span>{/if}
                    </fieldset>
                {/if}

                <label>
                    <span class="label">Name</span>
                    <input bind:value={form.name} placeholder="e.g. Production APNs key" required />
                    {#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}
                </label>

                {#if visiblePayloadFields.length}
                    <div class="grid gap-4 sm:grid-cols-2">
                        {#each visiblePayloadFields as field (field.name)}
                            <label class={visiblePayloadFields.length % 2 === 1 && field === visiblePayloadFields.at(-1) ? 'sm:col-span-2' : ''}>
                                <span class="label">{field.label}</span>
                                <input
                                    type={field.input}
                                    bind:value={form.payload[field.name]}
                                    placeholder={fieldPlaceholder(field)}
                                    autocomplete="off"
                                    required={field.required && fieldRequired(field)}
                                />
                                {#if editing && field.input === 'password' && !fieldRequired(field)}<span class="mt-1 block text-[10px] text-faint">Already configured. Enter a new value only to replace it.</span>{/if}
                                {#if field.hint}<span class="mt-1 block text-[10px] text-faint">{field.hint}</span>{/if}
                                {#if form.errors[`payload.${field.name}`]}<span class="error">{form.errors[`payload.${field.name}`]}</span>{/if}
                            </label>
                        {/each}
                    </div>
                {/if}

                {#if isExternal}
                    <VaultReferenceFields bind:this={vaultFieldsRef} {form} providers={vaultProviders} />
                {:else}
                    <label class="block">
                        <span class="label">Credential file <span class="font-normal text-faint">({credentialFileRequired() ? 'required' : 'optional'}, max 20 MB)</span></span>
                        <span class="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-control bg-surface-sunken px-4 py-4 transition hover:border-brand hover:bg-brand-soft">
                            <span class="grid h-9 w-9 place-items-center rounded-sm bg-surface-raised text-brand shadow-sm"><Upload class="h-4.5 w-4.5" strokeWidth={1.8} /></span>
                            <span class="min-w-0">
                                <strong class="block truncate text-xs text-text">{form.file?.name || (editing?.file && !credentialTypeChanged ? `Keep ${editing.file.name}` : 'Choose a file')}</strong>
                                <small class="text-[10px] text-faint">{credentialTypeChanged ? 'Changing the type requires a matching replacement file.' : selectedSchema?.file.hint || 'Credential file'}</small>
                            </span>
                            <input type="file" class="hidden" accept={selectedSchema?.file.accept} onchange={pickFile} />
                        </span>
                        {#if form.errors.file}<span class="error">{form.errors.file}</span>{/if}
                    </label>
                {/if}

                {#if !editing}
                    <label class="flex cursor-pointer items-start justify-between gap-4 rounded-md border border-border bg-surface-sunken px-4 py-3.5">
                        <span>
                            <span class="block text-xs font-semibold text-text">Check uniqueness</span>
                            <span class="mt-1 block text-[11px] leading-4 text-muted">If an identical credential already exists in this team vault, reuse it and do not store another database record or file.</span>
                        </span>
                        <span class="relative mt-0.5 shrink-0">
                            <input class="sr-only" type="checkbox" role="switch" bind:checked={form.check_uniqueness} />
                            <span class="block h-5 w-9 rounded-full transition {form.check_uniqueness ? 'bg-brand' : 'bg-border-control'}">
                                <span class="block h-4 w-4 translate-y-0.5 rounded-full bg-surface-raised shadow-sm transition {form.check_uniqueness ? 'translate-x-[18px]' : 'translate-x-0.5'}"></span>
                            </span>
                        </span>
                    </label>
                {/if}

                {#if otherErrors.length}
                    <div class="rounded-md border border-danger/30 bg-danger/5 p-3.5">
                        <p class="text-xs font-semibold text-danger">This could not be saved</p>
                        <ul class="mt-1.5 space-y-1">
                            {#each otherErrors as [key, message] (key)}
                                <li class="text-xs text-danger"><code class="mr-1 text-[10px] text-danger/70">{key}</code>{message}</li>
                            {/each}
                        </ul>
                    </div>
                {/if}
            </div>

            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4">
                <button type="button" class="secondary" onclick={close}>Cancel</button>
                <button disabled={form.processing || (!editing && !selectedIdentifier)}>{form.processing ? 'Saving…' : editing ? 'Save changes' : 'Add credential'}</button>
            </div>
        </form>
    </div>
{/if}
