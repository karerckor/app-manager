<script>
    import { useForm } from '@inertiajs/svelte';
    import { Upload } from '@lucide/svelte';

    export let app;
    export let identifiers = [];
    export let credentialTypes = [];
    export let buildProfiles = [];
    export let editing = null;
    export let open;
    export let onClose;
    export let onSaved;

    const payloadFor = (type) => Object.fromEntries((type?.fields ?? []).map((field) => [field.name, '']));
    const profilesFor = (type, platform) => (type?.profiles ?? []).filter((profile) => profile.platform === platform);

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
    });

    $: selectedIdentifier = identifiers.find((identifier) => String(identifier.id) === String(credentialForm.app_identifier_id));
    $: availableCredentialTypes = credentialTypes.filter((type) => type.platforms.includes(selectedIdentifier?.platform));
    $: selectedSchema = credentialTypes.find((type) => type.value === credentialForm.type);
    $: availableProfiles = profilesFor(selectedSchema, selectedIdentifier?.platform);
    $: selectedProfile = availableProfiles.find((profile) => profile.value === credentialForm.profile_type);
    $: credentialTypeChanged = editing && credentialForm.type !== editing.type;
    $: credentialFileRequired = !editing || credentialTypeChanged || !editing.file;

    const schemaFor = (type) => credentialTypes.find((definition) => definition.value === type);
    const typeLabel = (type) => schemaFor(type)?.label ?? type.replaceAll('_', ' ');

    const openCreateCredential = () => {
        const identifier = identifiers[0] ?? null;
        const schema = credentialTypes.find((type) => type.platforms.includes(identifier?.platform)) ?? credentialTypes[0];
        credentialForm.mobile_app_id = app.id;
        credentialForm.app_identifier_id = identifier?.id ?? '';
        credentialForm.name = '';
        credentialForm.expires_at = '';
        credentialForm.type = schema?.value ?? '';
        credentialForm.profile_type = profilesFor(schema, identifier?.platform)[0]?.value ?? '';
        credentialForm.payload = payloadFor(schema);
        credentialForm.file = null;
        credentialForm.clearErrors();
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
        credentialForm.clearErrors();
    };

    let wasOpen = false;
    $: if (open !== wasOpen) {
        wasOpen = open;

        if (open) {
            if (editing) {
                openEditCredential(editing);
            } else {
                openCreateCredential();
            }
        }
    }

    const closeCredential = () => {
        credentialForm.clearErrors();
        onClose();
    };
    const saveCredential = () => credentialForm.post(editing?.update_url ?? app.urls.credentials, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { onSaved(credentialForm.app_identifier_id); closeCredential(); },
    });
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
    };
    const changeCredentialType = (event) => selectCredentialType(event.currentTarget.value);
    const pickCredentialFile = (event) => credentialForm.file = event.currentTarget.files?.[0] ?? null;
    const credentialFieldRequired = (field) => !editing
        || editing.type !== credentialForm.type
        || field.input !== 'password'
        || !editing.configured_fields.includes(field.name);
    const credentialFieldPlaceholder = (field) => editing
        && editing.type === credentialForm.type
        && field.input === 'password'
        && editing.configured_fields.includes(field.name)
            ? 'Leave blank to keep the current value'
            : field.hint || '';
</script>

{#if open}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeCredential()}>
        <form class="modal" on:submit|preventDefault={saveCredential}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div><h2 class="text-lg font-bold">{editing ? 'Edit credential' : 'Add credential'}</h2><p class="mt-1 text-sm text-slate-500">{editing ? 'Update its attachment, metadata, secure fields, or file.' : 'Choose where the build runs, then upload the matching signing file.'}</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeCredential}>✕</button>
            </div>
            <div class="space-y-5 p-6">
                <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <span class="rounded bg-white px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-slate-500 uppercase ring-1 ring-slate-200">{selectedIdentifier?.platform}</span>
                    <span class="min-w-0 truncate text-xs text-slate-600">Attaching to <code class="font-semibold text-slate-800">{selectedIdentifier?.identifier}</code></span>
                    {#if credentialForm.errors.app_identifier_id}<span class="error ml-auto">{credentialForm.errors.app_identifier_id}</span>{/if}
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label>
                        <span class="label">Credential type</span>
                        <select value={credentialForm.type} on:change={changeCredentialType}>
                            {#each availableCredentialTypes as type}<option value={type.value}>{type.label}</option>{/each}
                        </select>
                        {#if credentialForm.errors.type}<span class="error">{credentialForm.errors.type}</span>{/if}
                    </label>
                    <label>
                        <span class="label">Expiration <span class="font-normal text-slate-400">(optional)</span></span>
                        <input type="date" bind:value={credentialForm.expires_at} />
                        {#if credentialForm.errors.expires_at}<span class="error">{credentialForm.errors.expires_at}</span>{/if}
                    </label>
                </div>
                {#if selectedSchema?.attachment === 'profile'}
                    <fieldset>
                        <legend class="label">Build target</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            {#each availableProfiles as profile}
                                <label class="cursor-pointer rounded-xl border p-3.5 transition {credentialForm.profile_type === profile.value ? 'border-indigo-500 bg-indigo-50/70 ring-2 ring-indigo-100' : 'border-slate-200 bg-white hover:border-slate-300'}">
                                    <input class="sr-only" type="radio" name="profile_type" value={profile.value} bind:group={credentialForm.profile_type} required />
                                    <span class="flex items-center justify-between gap-3">
                                        <strong class="text-xs text-slate-800">{profile.label}</strong>
                                        <span class="rounded-md bg-white px-2 py-0.5 font-mono text-[10px] font-bold text-indigo-600 shadow-sm ring-1 ring-slate-200">{profile.artifact}</span>
                                    </span>
                                    <span class="mt-1.5 block text-[10px] leading-4 text-slate-500">{profile.description}</span>
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

                {#if selectedSchema?.fields.length}
                    <div class="grid gap-4 sm:grid-cols-2">
                        {#each selectedSchema.fields as field}
                            <label class={selectedSchema.fields.length % 2 === 1 && field === selectedSchema.fields.at(-1) ? 'sm:col-span-2' : ''}>
                                <span class="label">{field.label}</span>
                                <input
                                    type={field.input}
                                    bind:value={credentialForm.payload[field.name]}
                                    placeholder={credentialFieldPlaceholder(field)}
                                    autocomplete="off"
                                    required={field.required && credentialFieldRequired(field)}
                                />
                                {#if editing && field.input === 'password' && !credentialFieldRequired(field)}<span class="mt-1 block text-[10px] text-slate-400">Already configured. Enter a new value only to replace it.</span>{/if}
                                {#if field.hint}<span class="mt-1 block text-[10px] text-slate-400">{field.hint}</span>{/if}
                                {#if credentialForm.errors[`payload.${field.name}`]}<span class="error">{credentialForm.errors[`payload.${field.name}`]}</span>{/if}
                            </label>
                        {/each}
                    </div>
                {/if}

                <label class="block">
                    <span class="label">Credential file <span class="font-normal text-slate-400">({credentialFileRequired ? 'required' : 'optional'}, max 20 MB)</span></span>
                    <span class="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 transition hover:border-indigo-400 hover:bg-indigo-50/40">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-white text-indigo-600 shadow-sm"><Upload class="h-4.5 w-4.5" strokeWidth={1.8} /></span>
                        <span class="min-w-0"><strong class="block truncate text-xs text-slate-700">{credentialForm.file?.name || (editing?.file && !credentialTypeChanged ? `Keep ${editing.file.name}` : 'Choose a file')}</strong><small class="text-[10px] text-slate-400">{credentialTypeChanged ? 'Changing the type requires a matching replacement file.' : selectedSchema?.file.hint || 'Credential file'}</small></span>
                        <input type="file" class="hidden" accept={selectedSchema?.file.accept} on:change={pickCredentialFile} />
                    </span>
                    {#if credentialForm.errors.file}<span class="error">{credentialForm.errors.file}</span>{/if}
                </label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeCredential}>Cancel</button><button disabled={credentialForm.processing}>{credentialForm.processing ? 'Saving…' : editing ? 'Save changes' : 'Add credential'}</button></div>
        </form>
    </div>
{/if}
