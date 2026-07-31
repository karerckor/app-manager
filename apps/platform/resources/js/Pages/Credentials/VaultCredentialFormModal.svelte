<script>
    import { useForm } from '@inertiajs/svelte';
    import { ShieldCheck, Upload } from '@lucide/svelte';

    export let apps = [];
    export let credentialTypes = [];
    export let checkUniquenessByDefault = true;
    export let editing = null;
    export let open;
    export let onClose;
    export let onSaved;

    const payloadFor = (type) => Object.fromEntries((type?.fields ?? []).map((field) => [field.name, '']));
    const profilesFor = (type, platform) => (type?.profiles ?? []).filter((profile) => profile.platform === platform);
    const firstConfiguredApp = () => apps.find((app) => app.identifiers.length) ?? apps[0] ?? null;

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
    });
    let credentialTypeChanged = false;

    $: selectedApp = apps.find((app) => String(app.id) === String(form.mobile_app_id));
    $: selectedIdentifier = selectedApp?.identifiers.find((identifier) => String(identifier.id) === String(form.app_identifier_id));
    $: availableCredentialTypes = editing
        ? credentialTypes
        : credentialTypes.filter((type) => type.platforms.includes(selectedIdentifier?.platform));
    $: selectedSchema = credentialTypes.find((type) => type.value === form.type);
    $: availableProfiles = profilesFor(selectedSchema, selectedIdentifier?.platform);
    $: typeLocked = Boolean(editing?.attachments?.length);
    const credentialFileRequired = () => !editing || credentialTypeChanged || !editing.file;

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
        credentialTypeChanged = false;
        form.clearErrors();
    };

    let wasOpen = false;
    $: if (open !== wasOpen) {
        wasOpen = open;
        if (open) editing ? resetForEdit() : resetForCreate();
    }

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
        credentialTypeChanged = Boolean(editing) && String(value) !== String(editing.type);
    };
    const changeApp = (event) => {
        form.mobile_app_id = event.currentTarget.value;
        const app = apps.find((item) => String(item.id) === String(form.mobile_app_id));
        const identifier = app?.identifiers[0] ?? null;
        const schema = credentialTypes.find((type) => type.platforms.includes(identifier?.platform)) ?? credentialTypes[0];
        form.app_identifier_id = identifier?.id ?? '';
        setType(schema?.value ?? '', identifier?.platform);
    };
    const changeIdentifier = (event) => {
        form.app_identifier_id = event.currentTarget.value;
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
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && close()}>
        <form class="modal max-w-2xl" on:submit|preventDefault={save}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                        <ShieldCheck class="h-5 w-5" strokeWidth={1.8} />
                    </span>
                    <div>
                        <h2 class="text-lg font-bold">{editing ? 'Edit credential' : 'Add to Credentials Vault'}</h2>
                        <p class="mt-1 text-sm text-slate-500">{editing ? 'Update the credential, expiration, or secure file.' : 'Store a signing asset and attach it to an application.'}</p>
                    </div>
                </div>
                <button type="button" class="ghost -mr-2 p-2" on:click={close} aria-label="Close">✕</button>
            </div>

            <div class="space-y-5 p-6">
                {#if editing}
                    <div class="rounded-xl border border-violet-100 bg-violet-50/60 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-xs font-bold text-violet-950">Used by</p>
                                <p class="mt-0.5 text-[11px] text-violet-700">Attachments are preserved when this credential is edited.</p>
                            </div>
                            <span class="rounded-full bg-white px-2 py-1 text-[10px] font-semibold text-violet-700 ring-1 ring-violet-100">
                                {editing.attachments.length} target{editing.attachments.length === 1 ? '' : 's'}
                            </span>
                        </div>
                        {#if editing.attachments.length}
                            <div class="mt-3 flex flex-wrap gap-2">
                                {#each editing.attachments as attachment}
                                    <a href={attachment.url} class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-violet-100 bg-white px-2.5 py-1.5 text-[11px] font-medium text-slate-600 shadow-sm transition hover:border-violet-200 hover:text-violet-700">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full {attachment.platform === 'ios' ? 'bg-slate-900' : 'bg-emerald-500'}"></span>
                                        <span class="truncate">{attachment.app_name}</span>
                                        <span class="text-slate-300">/</span>
                                        <code class="truncate text-[10px]">{attachment.identifier}</code>
                                        {#if attachment.profile}<span class="rounded bg-indigo-50 px-1 py-0.5 text-[9px] font-bold text-indigo-600">{attachment.profile.label}</span>{/if}
                                    </a>
                                {/each}
                            </div>
                            <p class="mt-3 text-[10px] text-violet-700">Add or remove usage from each app identifier page.</p>
                        {:else}
                            <p class="mt-3 text-[11px] font-medium text-amber-700">Not attached yet. Add it from an app identifier page.</p>
                        {/if}
                    </div>
                {:else}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label>
                            <span class="label">Application</span>
                            <select value={form.mobile_app_id} on:change={changeApp}>
                                {#each apps as app}<option value={app.id} disabled={!app.identifiers.length}>{app.name}{app.identifiers.length ? '' : ' — no identifiers'}</option>{/each}
                            </select>
                            {#if form.errors.mobile_app_id}<span class="error">{form.errors.mobile_app_id}</span>{/if}
                        </label>
                        <label>
                            <span class="label">App identifier</span>
                            <select value={form.app_identifier_id} on:change={changeIdentifier} required>
                                {#each selectedApp?.identifiers ?? [] as identifier}
                                    <option value={identifier.id}>{identifier.name || identifier.identifier} · {identifier.platform.toUpperCase()}</option>
                                {/each}
                            </select>
                            {#if form.errors.app_identifier_id}<span class="error">{form.errors.app_identifier_id}</span>{/if}
                        </label>
                    </div>
                {/if}

                <div class="grid gap-4 sm:grid-cols-2">
                    <label>
                        <span class="label">Credential type</span>
                        <select value={form.type} on:change={(event) => setType(event.currentTarget.value)} disabled={typeLocked}>
                            {#each availableCredentialTypes as type}<option value={type.value}>{type.label}</option>{/each}
                        </select>
                        {#if typeLocked}<span class="mt-1 block text-[10px] text-slate-400">Detach this credential from all targets before changing its type.</span>{/if}
                        {#if form.errors.type}<span class="error">{form.errors.type}</span>{/if}
                    </label>
                    <label>
                        <span class="label">Expiration <span class="font-normal text-slate-400">(optional)</span></span>
                        <input type="date" bind:value={form.expires_at} />
                        {#if form.errors.expires_at}<span class="error">{form.errors.expires_at}</span>{/if}
                    </label>
                </div>

                {#if !editing && selectedSchema?.attachment === 'profile'}
                    <fieldset>
                        <legend class="label">Build target</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            {#each availableProfiles as profile}
                                <label class="cursor-pointer rounded-xl border p-3.5 transition {form.profile_type === profile.value ? 'border-indigo-500 bg-indigo-50/70 ring-2 ring-indigo-100' : 'border-slate-200 bg-white hover:border-slate-300'}">
                                    <input class="sr-only" type="radio" name="profile_type" value={profile.value} bind:group={form.profile_type} required />
                                    <span class="flex items-center justify-between gap-3">
                                        <strong class="text-xs text-slate-800">{profile.label}</strong>
                                        <span class="rounded-md bg-white px-2 py-0.5 font-mono text-[10px] font-bold text-indigo-600 shadow-sm ring-1 ring-slate-200">{profile.artifact}</span>
                                    </span>
                                    <span class="mt-1.5 block text-[10px] leading-4 text-slate-500">{profile.description}</span>
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

                {#if selectedSchema?.fields.length}
                    <div class="grid gap-4 sm:grid-cols-2">
                        {#each selectedSchema.fields as field}
                            <label class={selectedSchema.fields.length % 2 === 1 && field === selectedSchema.fields.at(-1) ? 'sm:col-span-2' : ''}>
                                <span class="label">{field.label}</span>
                                <input
                                    type={field.input}
                                    bind:value={form.payload[field.name]}
                                    placeholder={fieldPlaceholder(field)}
                                    autocomplete="off"
                                    required={field.required && fieldRequired(field)}
                                />
                                {#if editing && field.input === 'password' && !fieldRequired(field)}<span class="mt-1 block text-[10px] text-slate-400">Already configured. Enter a new value only to replace it.</span>{/if}
                                {#if field.hint}<span class="mt-1 block text-[10px] text-slate-400">{field.hint}</span>{/if}
                                {#if form.errors[`payload.${field.name}`]}<span class="error">{form.errors[`payload.${field.name}`]}</span>{/if}
                            </label>
                        {/each}
                    </div>
                {/if}

                <label class="block">
                    <span class="label">Credential file <span class="font-normal text-slate-400">({credentialFileRequired() ? 'required' : 'optional'}, max 20 MB)</span></span>
                    <span class="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 transition hover:border-indigo-400 hover:bg-indigo-50/40">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-white text-indigo-600 shadow-sm"><Upload class="h-4.5 w-4.5" strokeWidth={1.8} /></span>
                        <span class="min-w-0">
                            <strong class="block truncate text-xs text-slate-700">{form.file?.name || (editing?.file && !credentialTypeChanged ? `Keep ${editing.file.name}` : 'Choose a file')}</strong>
                            <small class="text-[10px] text-slate-400">{credentialTypeChanged ? 'Changing the type requires a matching replacement file.' : selectedSchema?.file.hint || 'Credential file'}</small>
                        </span>
                        <input type="file" class="hidden" accept={selectedSchema?.file.accept} on:change={pickFile} />
                    </span>
                    {#if form.errors.file}<span class="error">{form.errors.file}</span>{/if}
                </label>

                {#if !editing}
                    <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3.5">
                        <span>
                            <span class="block text-xs font-semibold text-slate-800">Check uniqueness</span>
                            <span class="mt-1 block text-[11px] leading-4 text-slate-500">If an identical credential already exists in this team vault, reuse it and do not store another database record or file.</span>
                        </span>
                        <span class="relative mt-0.5 shrink-0">
                            <input class="sr-only" type="checkbox" role="switch" bind:checked={form.check_uniqueness} />
                            <span class="block h-5 w-9 rounded-full transition {form.check_uniqueness ? 'bg-indigo-600' : 'bg-slate-300'}">
                                <span class="block h-4 w-4 translate-y-0.5 rounded-full bg-white shadow-sm transition {form.check_uniqueness ? 'translate-x-[18px]' : 'translate-x-0.5'}"></span>
                            </span>
                        </span>
                    </label>
                {/if}
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4">
                <button type="button" class="secondary" on:click={close}>Cancel</button>
                <button disabled={form.processing || (!editing && !selectedIdentifier)}>{form.processing ? 'Saving…' : editing ? 'Save changes' : 'Add credential'}</button>
            </div>
        </form>
    </div>
{/if}
