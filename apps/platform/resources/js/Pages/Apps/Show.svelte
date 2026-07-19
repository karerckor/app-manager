<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { Pencil, Trash2, Plus, ChevronRight, KeyRound, Clock, Check, TriangleAlert, Download, Upload, Play, CirclePlus, Package, Image as ImageIcon } from '@lucide/svelte';
    import AppShell from '../../Components/AppShell.svelte';
    import TagEditor from '../../Components/TagEditor.svelte';

    export let auth;
    export let flash = {};
    export let app;
    export let identifiers = [];
    export let credentialTypes = [];
    export let buildProfiles = [];
    export let pipelines = [];
    export let builds = [];
    export let navigationTeams = [];
    export let navigationTeam = null;

    let tab = 'overview';
    let expandedIdentifierId = null;
    let showIdentifier = false;
    let showCredential = false;
    let editingIdentifier = null;
    let editingCredential = null;
    let showEdit = false;
    let confirmingDelete = false;
    let deleting = false;
    let expandedBuildId = null;
    let showManualBuild = false;
    let launchingPipeline = null;
    let forcingBuild = null;
    let manualParametersText = '{}';
    let manualParametersError = '';
    let pipelineParametersText = '{}';
    let pipelineParametersError = '';

    const editForm = useForm({ name: app.name, description: app.description ?? '' });

    const openEdit = () => {
        editForm.name = app.name;
        editForm.description = app.description ?? '';
        editForm.clearErrors();
        confirmingDelete = false;
        showEdit = true;
    };
    const closeEdit = () => {
        showEdit = false;
        confirmingDelete = false;
        editForm.clearErrors();
    };
    const saveEdit = () => editForm.patch(app.urls.update, { preserveScroll: true, onSuccess: closeEdit });
    const deleteApp = () => router.delete(app.urls.destroy, { onStart: () => deleting = true, onFinish: () => deleting = false });

    const identifierForm = useForm({ platform: 'ios', identifier: '', name: '', version: '', version_code: '' });
    const initialIdentifier = identifiers[0] ?? null;
    const initialType = credentialTypes.find((type) => type.platforms.includes(initialIdentifier?.platform)) ?? credentialTypes[0];
    const payloadFor = (type) => Object.fromEntries((type?.fields ?? []).map((field) => [field.name, '']));
    const profilesFor = (type, platform) => (type?.profiles ?? []).filter((profile) => profile.platform === platform);
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
    const profilesForIdentifier = (identifierId) => {
        const identifier = identifiers.find((item) => String(item.id) === String(identifierId));

        if (identifier) return buildProfiles.filter((profile) => profile.platform === identifier.platform);

        return buildProfiles.filter((profile, index, items) => items.findIndex((item) => item.value === profile.value) === index);
    };
    const initialManualProfiles = profilesForIdentifier(initialIdentifier?.id ?? '');
    const manualBuildForm = useForm({
        request_uuid: '',
        app_identifier_id: initialIdentifier?.id ?? '',
        profile_type: initialManualProfiles[0]?.value ?? '',
        parameters: {},
    });
    const pipelineBuildForm = useForm({ request_uuid: '', ref: '', inputs: {} });
    const forceStatusForm = useForm({ status: 'cancelled' });

    const artifactForm = useForm({ name: '', metadata: '', file: null });

    const uploadArtifact = (build) => artifactForm
        .transform((data) => ({ ...data, metadata: data.metadata?.trim() ? data.metadata : null }))
        .post(build.artifacts_url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => artifactForm.reset(),
        });

    const deleteArtifact = (artifact) => router.delete(artifact.delete_url, { preserveScroll: true });

    let confirmingIdentifierDelete = null;
    let confirmingCredentialDelete = null;
    const deleteIdentifier = (identifier) => router.delete(identifier.delete_url, {
        preserveScroll: true,
        onFinish: () => confirmingIdentifierDelete = null,
    });
    const deleteCredential = (credential) => router.delete(credential.delete_url, {
        preserveScroll: true,
        onFinish: () => confirmingCredentialDelete = null,
    });

    const formatSize = (bytes) => {
        if (bytes == null) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
        return `${value.toFixed(value < 10 && unit > 0 ? 1 : 0)} ${units[unit]}`;
    };

    $: selectedIdentifier = identifiers.find((identifier) => String(identifier.id) === String(credentialForm.app_identifier_id));
    $: availableCredentialTypes = credentialTypes.filter((type) => type.platforms.includes(selectedIdentifier?.platform));
    $: selectedSchema = credentialTypes.find((type) => type.value === credentialForm.type);
    $: availableProfiles = profilesFor(selectedSchema, selectedIdentifier?.platform);
    $: selectedProfile = availableProfiles.find((profile) => profile.value === credentialForm.profile_type);
    $: credentialTypeChanged = editingCredential && credentialForm.type !== editingCredential.type;
    $: credentialFileRequired = !editingCredential || credentialTypeChanged || !editingCredential.file;
    $: manualProfileOptions = profilesForIdentifier(manualBuildForm.app_identifier_id);
    $: allTags = (() => {
        const acc = [];
        const push = (list) => (list || []).forEach((t) => acc.push(Array.isArray(t) ? [t[0], t[1] ?? null] : [t, null]));
        push(app.tags);
        builds.forEach((build) => { push(build.tags); (build.artifacts || []).forEach((artifact) => push(artifact.tags)); });
        identifiers.forEach((identifier) => {
            (identifier.services || []).forEach((credential) => push(credential.tags));
            (identifier.build_targets || []).forEach((target) => (target.credentials || []).forEach((credential) => push(credential.tags)));
        });
        return acc;
    })();

    const closeIdentifier = () => {
        showIdentifier = false;
        editingIdentifier = null;
        identifierForm.reset();
        identifierForm.clearErrors();
    };
    const openCreateIdentifier = () => {
        editingIdentifier = null;
        identifierForm.reset();
        identifierForm.clearErrors();
        showIdentifier = true;
    };
    const openEditIdentifier = (identifier) => {
        editingIdentifier = identifier;
        identifierForm.platform = identifier.platform;
        identifierForm.identifier = identifier.identifier;
        identifierForm.name = identifier.name ?? '';
        identifierForm.version = identifier.version ?? '';
        identifierForm.version_code = identifier.version_code ?? '';
        identifierForm.clearErrors();
        showIdentifier = true;
    };
    const saveIdentifier = () => {
        const options = { preserveScroll: true, onSuccess: closeIdentifier };

        if (editingIdentifier) {
            identifierForm.patch(editingIdentifier.update_url, options);
        } else {
            identifierForm.post(app.urls.identifiers, options);
        }
    };
    const changeIdentifierPlatform = (event) => {
        identifierForm.platform = event.currentTarget.value;
        if (identifierForm.platform !== 'android') identifierForm.version_code = '';
    };
    const saveCredential = () => credentialForm.post(editingCredential?.update_url ?? app.urls.credentials, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { expandedIdentifierId = credentialForm.app_identifier_id; closeCredential(); },
    });
    const selectCredentialType = (value, platform = selectedIdentifier?.platform) => {
        const schema = credentialTypes.find((type) => type.value === value);
        const originalProfile = editingCredential?.type === value ? editingCredential.profile?.value : null;
        const profiles = profilesFor(schema, platform);
        credentialForm.type = schema?.value ?? '';
        credentialForm.profile_type = profiles.some((profile) => profile.value === originalProfile)
            ? originalProfile
            : profiles[0]?.value ?? '';
        credentialForm.payload = editingCredential?.type === value
            ? { ...payloadFor(schema), ...editingCredential.payload }
            : payloadFor(schema);
        credentialForm.file = null;
    };
    const toggleIdentifier = (identifier) => expandedIdentifierId = expandedIdentifierId === identifier.id ? null : identifier.id;
    const changeCredentialType = (event) => selectCredentialType(event.currentTarget.value);
    const uploadAsset = (collection, event) => {
        const file = event.currentTarget.files?.[0];
        if (file) router.post(app.urls.assets.replace('__collection__', collection), { file }, { forceFormData: true, preserveScroll: true });
        event.currentTarget.value = '';
    };
    const uploadCredential = (credential, event) => {
        const file = event.currentTarget.files?.[0];
        if (file) router.post(credential.upload_url, { file }, { forceFormData: true, preserveScroll: true });
        event.currentTarget.value = '';
    };
    const pickCredentialFile = (event) => credentialForm.file = event.currentTarget.files?.[0] ?? null;
    const openCreateCredential = (identifier) => {
        editingCredential = null;
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
        showCredential = true;
    };
    const openEditCredential = (credential) => {
        editingCredential = credential;
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
        showCredential = true;
    };
    const closeCredential = () => { showCredential = false; editingCredential = null; credentialForm.clearErrors(); };
    const credentialFieldRequired = (field) => !editingCredential
        || editingCredential.type !== credentialForm.type
        || field.input !== 'password'
        || !editingCredential.configured_fields.includes(field.name);
    const credentialFieldPlaceholder = (field) => editingCredential
        && editingCredential.type === credentialForm.type
        && field.input === 'password'
        && editingCredential.configured_fields.includes(field.name)
            ? 'Leave blank to keep the current value'
            : field.hint || '';
    const initials = (name) => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
    const schemaFor = (type) => credentialTypes.find((definition) => definition.value === type);
    const typeLabel = (type) => schemaFor(type)?.label ?? type.replaceAll('_', ' ');
    const formatBytes = (bytes) => bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    const formatDate = (value) => value ? new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value)) : 'No expiration';
    const formatDateTime = (value) => value ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
    const formatParameters = (value) => JSON.stringify(value ?? {}, null, 2);
    const buildStatusClass = (status) => ({ completed: 'bg-emerald-50 text-emerald-700', running: 'bg-sky-50 text-sky-700', pending: 'bg-indigo-50 text-indigo-700', cancel_requested: 'bg-amber-50 text-amber-700', failed: 'bg-rose-50 text-rose-700', cancelled: 'bg-slate-100 text-slate-600' }[status] ?? 'bg-slate-100 text-slate-600');
    const firstFormError = (form, prefix) => Object.entries(form.errors ?? {}).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1];
    const parseParameters = (value, setError) => {
        try {
            const parsed = JSON.parse(value);

            if (parsed === null || Array.isArray(parsed) || typeof parsed !== 'object') {
                setError('Parameters must be a JSON object.');

                return null;
            }

            setError('');

            return parsed;
        } catch (error) {
            setError(`Invalid JSON: ${error.message}`);

            return null;
        }
    };
    const openManualBuild = () => {
        manualBuildForm.request_uuid = crypto.randomUUID();
        manualBuildForm.app_identifier_id = identifiers[0]?.id ?? '';
        manualBuildForm.profile_type = profilesForIdentifier(manualBuildForm.app_identifier_id)[0]?.value ?? '';
        manualBuildForm.parameters = {};
        manualBuildForm.clearErrors();
        manualParametersText = '{}';
        manualParametersError = '';
        showManualBuild = true;
    };
    const closeManualBuild = () => {
        showManualBuild = false;
        manualBuildForm.clearErrors();
        manualParametersError = '';
    };
    const changeManualIdentifier = (event) => {
        manualBuildForm.app_identifier_id = event.currentTarget.value;
        const profiles = profilesForIdentifier(manualBuildForm.app_identifier_id);

        if (!profiles.some((profile) => profile.value === manualBuildForm.profile_type)) {
            manualBuildForm.profile_type = profiles[0]?.value ?? '';
        }
    };
    const createManualBuild = () => {
        const parameters = parseParameters(manualParametersText, (message) => manualParametersError = message);

        if (parameters === null) return;

        manualBuildForm.parameters = parameters;
        manualBuildForm.post(app.urls.manual_builds, {
            preserveScroll: true,
            onSuccess: () => { closeManualBuild(); tab = 'builds'; },
        });
    };
    const openPipelineBuild = (pipeline) => {
        launchingPipeline = pipeline;
        pipelineBuildForm.request_uuid = crypto.randomUUID();
        pipelineBuildForm.ref = pipeline.default_ref;
        pipelineBuildForm.inputs = pipeline.input_defaults ?? {};
        pipelineBuildForm.clearErrors();
        pipelineParametersText = formatParameters(pipeline.input_defaults);
        pipelineParametersError = '';
    };
    const closePipelineBuild = () => {
        launchingPipeline = null;
        pipelineBuildForm.clearErrors();
        pipelineParametersError = '';
    };
    const runPipeline = () => {
        const inputs = parseParameters(pipelineParametersText, (message) => pipelineParametersError = message);

        if (inputs === null) return;

        pipelineBuildForm.inputs = inputs;
        pipelineBuildForm.post(launchingPipeline.run_url, {
            preserveScroll: true,
            onSuccess: () => { closePipelineBuild(); tab = 'builds'; },
        });
    };
    const cancelBuild = (build) => router.post(build.cancel_url, {}, { preserveScroll: true });
    const openForceStatus = (build) => {
        forcingBuild = build;
        forceStatusForm.status = build.status === 'cancel_requested' ? 'cancelled' : 'failed';
        forceStatusForm.clearErrors();
    };
    const closeForceStatus = () => {
        forcingBuild = null;
        forceStatusForm.clearErrors();
    };
    const forceBuildStatus = () => forceStatusForm.patch(forcingBuild.force_status_url, {
        preserveScroll: true,
        onSuccess: closeForceStatus,
    });
    const toggleBuildDetails = (build) => expandedBuildId = expandedBuildId === build.id ? null : build.id;
    const isExpiring = (value) => value && new Date(value).getTime() < Date.now() + 1000 * 60 * 60 * 24 * 30;

    onMount(() => {
        const timer = setInterval(() => {
            const waiting = builds.some((build) => !['completed', 'failed', 'cancelled'].includes(build.status) || build.logs_pending);

            if (waiting) {
                router.reload({ only: ['builds'], preserveScroll: true, preserveState: true });
            }
        }, 5000);

        return () => clearInterval(timer);
    });
</script>

<svelte:head><title>{app.name} · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam}>
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-9">
        <nav class="mb-6 flex items-center gap-2 text-xs font-medium text-slate-500"><Link href={app.urls.index} class="hover:text-indigo-600">Applications</Link><span class="text-slate-300">/</span><span class="text-slate-800">{app.name}</span></nav>

        <header class="mb-7 flex flex-wrap items-start justify-between gap-5">
            <div class="flex min-w-0 items-start gap-4">
                {#if app.assets.app_icon}<img src={app.assets.app_icon.preview_url} alt={`${app.name} icon`} class="h-14 w-14 shrink-0 rounded-2xl bg-white object-cover shadow-lg shadow-slate-200 ring-1 ring-slate-200" />{:else}<span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-sm font-bold text-white shadow-lg shadow-indigo-200">{initials(app.name)}</span>{/if}
                <div class="min-w-0">
                    <div class="flex items-center gap-2"><h1 class="truncate text-2xl font-bold tracking-tight">{app.name}</h1><span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 uppercase">Active</span></div>
                    <p class="mt-1 text-sm text-slate-500">{app.description || app.slug}</p>
                    <div class="mt-2.5"><TagEditor tags={app.tags} url={app.urls.tags} suggestions={allTags} /></div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="secondary flex items-center gap-2" on:click={openEdit}>
                    <Pencil class="h-4 w-4" strokeWidth={2.2} />
                    Edit
                </button>
                <button class="flex items-center gap-2" on:click={openCreateIdentifier}>
                    <Plus class="h-4 w-4" strokeWidth={2.2} />
                    Add identifier
                </button>
            </div>
        </header>

        {#if flash?.status}<div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>{flash.status}</div>{/if}

        <div class="mb-7 flex gap-1 border-b border-slate-200">
            <button class="rounded-none border-b-2 px-4 py-3 shadow-none {tab === 'overview' ? 'border-indigo-600 bg-transparent text-indigo-700' : 'border-transparent bg-transparent text-slate-500 hover:bg-transparent hover:text-slate-900'}" on:click={() => tab = 'overview'}>Overview</button>
            <button class="rounded-none border-b-2 px-4 py-3 shadow-none {tab === 'builds' ? 'border-indigo-600 bg-transparent text-indigo-700' : 'border-transparent bg-transparent text-slate-500 hover:bg-transparent hover:text-slate-900'}" on:click={() => tab = 'builds'}>Builds <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-600">{builds.length}</span></button>
        </div>

        {#snippet credentialRow(credential)}
            <div class="rounded-xl border border-slate-200 bg-white px-3.5 py-3 transition hover:border-slate-300">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-600"><KeyRound class="h-4 w-4" strokeWidth={1.8} /></span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{credential.name}</p>
                            <p class="mt-0.5 text-[11px] text-slate-500">{typeLabel(credential.type)}{credential.fields.length ? ` · ${credential.fields.length} encrypted field${credential.fields.length === 1 ? '' : 's'}` : ''}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold {isExpiring(credential.expires_at) ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'}"><Clock class="h-3 w-3" />{formatDate(credential.expires_at)}</span>
                                {#if credential.file}<span class="inline-flex items-center gap-1 text-[11px] text-slate-500"><Check class="h-3 w-3 text-emerald-500" />{credential.file.name} · {formatBytes(credential.file.size)}</span>{:else}<span class="inline-flex items-center gap-1 text-[11px] text-amber-600"><TriangleAlert class="h-3 w-3" />No file uploaded</span>{/if}
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-0.5 rounded-lg border border-slate-200 bg-slate-50/60 p-0.5">
                        {#if credential.file}<a href={credential.file.download_url} class="grid h-7 w-7 place-items-center rounded-md bg-transparent text-slate-500 shadow-none hover:bg-white hover:text-indigo-600 hover:shadow-sm" title="Download" aria-label="Download file"><Download class="h-4 w-4" strokeWidth={1.9} /></a>{/if}
                        <label for={`credential-${credential.id}`} class="grid h-7 w-7 cursor-pointer place-items-center rounded-md text-slate-500 hover:bg-white hover:text-indigo-600 hover:shadow-sm" title={credential.file ? 'Replace file' : 'Upload file'} aria-label={credential.file ? 'Replace file' : 'Upload file'}><Upload class="h-4 w-4" strokeWidth={1.9} /></label>
                        <input id={`credential-${credential.id}`} type="file" accept={schemaFor(credential.type)?.file.accept} class="hidden" on:change={(event) => uploadCredential(credential, event)} />
                        <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-slate-500 shadow-none hover:bg-white hover:text-indigo-600 hover:shadow-sm" title="Edit" aria-label="Edit credential" on:click={() => openEditCredential(credential)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                        <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-rose-500 shadow-none hover:bg-white hover:text-rose-600 hover:shadow-sm" title="Delete" aria-label="Delete credential" on:click={() => confirmingCredentialDelete = credential.id}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                    </div>
                </div>
                {#if confirmingCredentialDelete === credential.id}
                    <div class="mt-2.5 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-rose-50 px-3 py-2">
                        <span class="text-[11px] font-medium text-rose-700">{credential.attached_elsewhere ? 'Shared with other apps — this removes it everywhere.' : 'Delete this credential permanently?'}</span>
                        <span class="flex gap-2"><button type="button" class="ghost px-2.5 py-1 text-[11px]" on:click={() => confirmingCredentialDelete = null}>Cancel</button><button type="button" class="danger px-2.5 py-1 text-[11px]" on:click={() => deleteCredential(credential)}>Delete</button></span>
                    </div>
                {/if}
                <div class="mt-2.5 flex items-center gap-2 border-t border-slate-100 pt-2.5">
                    <span class="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">Tags</span>
                    <TagEditor tags={credential.tags} url={credential.tags_url} suggestions={allTags} />
                </div>
            </div>
        {/snippet}

        {#if tab === 'overview'}
            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(300px,0.8fr)]">
                <section class="card p-0">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h2 class="text-sm font-bold">App identifiers</h2><p class="mt-0.5 text-xs text-slate-500">Bundle IDs and package names for each platform.</p></div><button class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={openCreateIdentifier}><Plus class="h-4 w-4" strokeWidth={2.2} /> Add</button></div>
                    {#if identifiers.length}
                        <div class="divide-y divide-slate-100">
                            {#each identifiers as identifier}
                                <div class="px-5 py-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <button type="button" class="flex min-w-0 items-center gap-3 bg-transparent p-0 text-left shadow-none hover:bg-transparent" on:click={() => toggleIdentifier(identifier)} aria-expanded={expandedIdentifierId === identifier.id}>
                                            <ChevronRight class="h-4 w-4 shrink-0 text-slate-400 transition-transform {expandedIdentifierId === identifier.id ? 'rotate-90' : ''}" strokeWidth={2.4} />
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl {identifier.platform === 'ios' ? 'bg-slate-900 text-white' : 'bg-emerald-50 text-emerald-700'}">{#if identifier.platform === 'ios'}<svg viewBox="0 0 24 24" class="h-4.5 w-4.5" fill="currentColor"><path d="M16.7 12.9c0-2.4 2-3.6 2.1-3.7a4.5 4.5 0 0 0-3.5-1.9c-1.5-.1-2.9.9-3.6.9-.8 0-1.9-.9-3.1-.8a4.7 4.7 0 0 0-4 2.4c-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3.1 2.4 1.2-.1 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4a10.7 10.7 0 0 0 1.4-2.9 4.2 4.2 0 0 1-3-3.8ZM14.3 5.8A4.2 4.2 0 0 0 15.4 3a4.3 4.3 0 0 0-2.8 1.4 4 4 0 0 0-1.1 2.7 3.6 3.6 0 0 0 2.8-1.3Z"/></svg>{:else}<svg viewBox="0 0 24 24" class="h-5 w-5" fill="currentColor"><path d="m7.2 8.4-1.7-3a.5.5 0 0 1 .2-.7.5.5 0 0 1 .7.2l1.8 3A9 9 0 0 1 12 7c1.4 0 2.7.3 3.8.9l1.8-3a.5.5 0 1 1 .9.5l-1.7 3a7 7 0 0 1 3.2 5.3H4a7 7 0 0 1 3.2-5.3ZM8.3 11a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Zm7.4 0a.8.8 0 1 0 0-1.5.8.8 0 0 0 0 1.5Z"/></svg>{/if}</span>
                                            <span class="min-w-0"><span class="flex items-center gap-2"><span class="truncate text-sm font-semibold">{identifier.name || (identifier.platform === 'ios' ? 'iOS application' : 'Android application')}</span><span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-slate-500 uppercase">{identifier.platform}</span>{#if identifier.version || identifier.version_code}<span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[9px] font-bold text-indigo-600">{identifier.version ? `v${identifier.version}` : ''}{identifier.version_code ? `${identifier.version ? ' · ' : ''}code ${identifier.version_code}` : ''}</span>{/if}</span><code class="mt-1 block truncate text-xs text-slate-500">{identifier.identifier}</code></span>
                                        </button>
                                        <div class="flex shrink-0 items-center gap-2">
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600"><KeyRound class="h-3 w-3 text-slate-400" />{identifier.credentials_count}</span>
                                            <div class="flex items-center gap-0.5 rounded-lg border border-slate-200 bg-slate-50/60 p-0.5">
                                                <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-slate-500 shadow-none hover:bg-white hover:text-indigo-600 hover:shadow-sm" title="Edit" aria-label="Edit identifier" on:click={() => openEditIdentifier(identifier)}><Pencil class="h-4 w-4" strokeWidth={1.9} /></button>
                                                <button type="button" class="grid h-7 w-7 place-items-center rounded-md bg-transparent p-0 text-rose-500 shadow-none hover:bg-white hover:text-rose-600 hover:shadow-sm" title="Delete" aria-label="Delete identifier" on:click={() => confirmingIdentifierDelete = identifier.id}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                                            </div>
                                        </div>
                                    </div>
                                    {#if confirmingIdentifierDelete === identifier.id}
                                        <div class="mt-3 ml-7 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-rose-50 px-3 py-2">
                                            <span class="text-[11px] font-medium text-rose-700">{identifier.credentials_count > 0 ? `Delete this identifier? ${identifier.credentials_count} credential${identifier.credentials_count === 1 ? '' : 's'} will be detached.` : 'Delete this identifier?'}</span>
                                            <span class="flex gap-2"><button type="button" class="ghost px-2.5 py-1 text-[11px]" on:click={() => confirmingIdentifierDelete = null}>Cancel</button><button type="button" class="danger px-2.5 py-1 text-[11px]" on:click={() => deleteIdentifier(identifier)}>Delete</button></span>
                                        </div>
                                    {/if}
                                    {#if expandedIdentifierId === identifier.id}
                                        <div class="mt-4 space-y-4 pl-7">
                                            {#if identifier.services.length}
                                                <div>
                                                    <p class="mb-2 text-[10px] font-bold tracking-wider text-slate-400 uppercase">Services</p>
                                                    <div class="space-y-2">{#each identifier.services as credential (credential.id)}{@render credentialRow(credential)}{/each}</div>
                                                </div>
                                            {/if}
                                            {#each identifier.build_targets as target (target.type)}
                                                <div>
                                                    <p class="mb-2 flex items-center gap-2 text-[10px] font-bold tracking-wider text-slate-400 uppercase">Build target · {target.label}{#if target.artifact}<span class="font-mono text-slate-300 normal-case">{target.artifact}</span>{/if}</p>
                                                    <div class="space-y-2">{#each target.credentials as credential (credential.id)}{@render credentialRow(credential)}{/each}</div>
                                                </div>
                                            {/each}
                                            {#if !identifier.services.length && !identifier.build_targets.length}
                                                <p class="text-xs text-slate-500">No credentials attached yet. Add a signing key, service account, or platform configuration.</p>
                                            {/if}
                                            <button type="button" class="secondary flex items-center gap-1.5 px-3 py-2 text-xs" on:click={() => openCreateCredential(identifier)}><Plus class="h-4 w-4" strokeWidth={2.2} /> Add credential</button>
                                        </div>
                                    {/if}
                                </div>
                            {/each}
                        </div>
                    {:else}
                        <div class="px-6 py-12 text-center"><p class="text-sm font-semibold">No identifiers yet</p><p class="mt-1 text-xs text-slate-500">Add an iOS bundle ID or Android package name.</p><button class="mt-4 px-3 py-2 text-xs" on:click={openCreateIdentifier}>Add identifier</button></div>
                    {/if}
                </section>

                <aside class="space-y-6">
                    <section class="card">
                        <div class="mb-4"><h2 class="text-sm font-bold">Application details</h2><p class="mt-0.5 text-xs text-slate-500">Project metadata</p></div>
                        <dl class="space-y-3 text-xs"><div class="flex items-center justify-between gap-4"><dt class="text-slate-500">Slug</dt><dd><code class="rounded bg-slate-100 px-2 py-1 text-slate-700">{app.slug}</code></dd></div><div class="flex items-center justify-between gap-4"><dt class="text-slate-500">Created</dt><dd class="font-medium">{formatDate(app.created_at)}</dd></div><div class="flex items-center justify-between gap-4"><dt class="text-slate-500">Last updated</dt><dd class="font-medium">{formatDate(app.updated_at)}</dd></div></dl>
                    </section>

                    <section class="card">
                        <div class="mb-4"><h2 class="text-sm font-bold">App assets</h2><p class="mt-0.5 text-xs text-slate-500">Upload or download project images.</p></div>
                        <div class="space-y-3">
                            {#each [['app_icon', 'App icon', 'Square PNG or JPG'], ['splash_icon', 'Splash icon', 'PNG or JPG up to 10 MB']] as asset}
                                <div class="rounded-xl border border-slate-200 p-3">
                                    <div class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-lg bg-slate-100 text-slate-500"><ImageIcon class="h-4 w-4" strokeWidth={1.8} /></span><div class="min-w-0 flex-1"><p class="text-xs font-semibold">{asset[1]}</p><p class="mt-0.5 truncate text-[10px] text-slate-400">{app.assets[asset[0]]?.name || asset[2]}</p></div></div>
                                    <div class="mt-3 flex gap-2"><label for={`asset-${asset[0]}`} class="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-center text-[10px] font-semibold text-slate-600 transition hover:bg-slate-50">{app.assets[asset[0]] ? 'Replace' : 'Upload'}</label><input id={`asset-${asset[0]}`} class="hidden" type="file" accept="image/*" on:change={(event) => uploadAsset(asset[0], event)} />{#if app.assets[asset[0]]}<a href={app.assets[asset[0]].download_url} class="flex-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-center text-[10px] font-semibold text-slate-600 hover:bg-slate-200">Download</a>{/if}</div>
                                </div>
                            {/each}
                        </div>
                    </section>
                </aside>
            </div>
        {:else}
            <div class="space-y-6">
                {#if auth?.team?.permissions?.run_pipelines}
                    <section class="card p-0">
                        <div class="border-b border-slate-100 px-5 py-4"><h2 class="text-sm font-bold">Start a build</h2><p class="mt-1 text-xs text-slate-500">Trigger a connected CI/CD pipeline, or record a manual build without CI.</p></div>
                        <div>
                            {#each pipelines as pipeline, i}
                                <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-3.5 {i > 0 ? 'border-t border-slate-100' : ''}">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><Play class="h-4 w-4" fill="currentColor" /></span>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2"><p class="text-sm font-semibold text-slate-900">{pipeline.name}</p><span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[9px] font-bold text-indigo-600 uppercase">{pipeline.reporting_mode}</span></div>
                                            <p class="mt-0.5 truncate text-xs text-slate-500">{pipeline.provider} · {pipeline.repository} · {pipeline.workflow} · {pipeline.default_ref}</p>
                                        </div>
                                    </div>
                                    <button class="px-3 py-2 text-xs" on:click={() => openPipelineBuild(pipeline)}>Run build</button>
                                </div>
                            {/each}

                            {#if pipelines.length}<div class="flex items-center gap-3 px-5 pt-3"><span class="h-px flex-1 bg-slate-100"></span><span class="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">or</span><span class="h-px flex-1 bg-slate-100"></span></div>{/if}

                            <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-3.5 {pipelines.length ? 'pt-3' : ''}">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500"><CirclePlus class="h-4 w-4" strokeWidth={1.9} /></span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2"><p class="text-sm font-semibold text-slate-900">Manual build</p><span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500 uppercase">Manual</span></div>
                                        <p class="mt-0.5 text-xs text-slate-500">{pipelines.length ? 'No CI trigger — records a build and fires build webhooks.' : 'No pipelines connected yet. Configure one in Integrations, or record a build manually.'}</p>
                                    </div>
                                </div>
                                <button class="{pipelines.length ? 'secondary' : ''} px-3 py-2 text-xs" on:click={openManualBuild}>Create manual build</button>
                            </div>
                        </div>
                    </section>
                {/if}

                <section class="card overflow-hidden p-0">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 class="text-sm font-bold">Build history</h2><p class="mt-1 text-xs text-slate-500">Builds reported by CI providers and your own runners.</p></div>
                    {#if builds.length}
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[760px] text-left text-xs">
                                <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-400"><tr><th class="px-5 py-3">Pipeline</th><th class="px-4 py-3">Profile</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Artifacts</th><th class="px-4 py-3">Started</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    {#each builds as build}
                                        <tr class="align-top">
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-2"><span class="font-semibold">{build.pipeline_name || 'Manual build'}</span><span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500 uppercase">{build.reporting_mode}</span></div>
                                                <div class="mt-1.5"><TagEditor tags={build.tags} url={build.tags_url} suggestions={allTags} /></div>
                                            </td>
                                            <td class="px-4 py-4 text-slate-500">{build.profile_type}</td>
                                            <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold {buildStatusClass(build.status)}">{build.status.replaceAll('_', ' ')}</span></td>
                                            <td class="px-4 py-4">
                                                {#if build.artifacts.length}
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-semibold text-indigo-700"><Package class="h-3 w-3" />{build.artifacts.length} attached</span>
                                                {:else}
                                                    <span class="inline-flex items-center gap-1.5 text-[10px] font-medium text-slate-400"><Package class="h-3 w-3" strokeWidth={1.8} />None</span>
                                                {/if}
                                            </td>
                                            <td class="px-4 py-4 text-slate-500">{formatDateTime(build.started_at || build.created_at)}</td>
                                            <td class="px-5 py-4">
                                                <div class="flex justify-end gap-2">
                                                    <button class="ghost px-2.5 py-1.5 text-[10px]" on:click={() => toggleBuildDetails(build)}>{expandedBuildId === build.id ? 'Hide details' : 'Details'}</button>
                                                    {#if build.logs_pending}<span class="px-2 py-1.5 text-[10px] font-medium text-slate-400">Syncing logs…</span>{/if}
                                                    {#if build.external_url}<a href={build.external_url} target="_blank" rel="noreferrer" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-semibold text-slate-600 hover:bg-slate-50">Open run</a>{/if}
                                                    {#if build.can_cancel && auth?.team?.permissions?.run_pipelines}<button class="ghost px-2.5 py-1.5 text-[10px] text-rose-600" on:click={() => cancelBuild(build)}>Cancel</button>{/if}
                                                    {#if build.can_force_status}<button class="ghost px-2.5 py-1.5 text-[10px] text-amber-700" on:click={() => openForceStatus(build)}>Force status</button>{/if}
                                                </div>
                                            </td>
                                        </tr>
                                        {#if expandedBuildId === build.id}
                                            <tr>
                                                <td colspan="6" class="bg-slate-50 px-5 py-4">
                                                    <div class="grid gap-4 {build.logs ? 'lg:grid-cols-2' : ''}">
                                                        <div><p class="mb-2 text-[10px] font-bold tracking-wider text-slate-500 uppercase">Launch parameters</p><pre class="max-h-[24rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 px-4 py-3 font-mono text-[11px] leading-5 text-slate-200">{formatParameters(build.parameters)}</pre></div>
                                                        {#if build.logs}<div><p class="mb-2 text-[10px] font-bold tracking-wider text-slate-500 uppercase">Logs</p><pre class="max-h-[24rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 px-4 py-3 font-mono text-[11px] leading-5 text-slate-200">{build.logs}</pre></div>{/if}
                                                    </div>

                                                    <section class="mt-4 border-t border-slate-200 pt-4">
                                                        <div class="mb-2.5 flex items-center justify-between"><p class="text-[10px] font-bold tracking-wider text-slate-500 uppercase">Artifacts</p>{#if build.artifacts.length}<span class="text-[10px] font-medium text-slate-400">{build.artifacts.length} attached</span>{/if}</div>

                                                        {#if build.artifacts.length === 0}
                                                            <p class="rounded-lg border border-dashed border-slate-200 bg-white px-3 py-3 text-xs text-slate-500">No artifacts attached to this build yet.</p>
                                                        {:else}
                                                            <ul class="space-y-2">
                                                                {#each build.artifacts as artifact (artifact.id)}
                                                                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                                                                        <div class="flex min-w-0 items-center gap-2.5">
                                                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600"><Package class="h-4 w-4" strokeWidth={1.8} /></span>
                                                                            <div class="min-w-0"><p class="truncate text-xs font-semibold text-slate-900">{artifact.name}</p><p class="mt-0.5 text-[10px] text-slate-400">{artifact.size != null ? formatSize(artifact.size) : 'Unknown size'}</p></div>
                                                                        </div>
                                                                        <div class="flex items-center gap-2">
                                                                            <TagEditor tags={artifact.tags} url={artifact.tags_url} suggestions={allTags} />
                                                                            <a href={artifact.download_url} class="grid h-7 w-7 place-items-center rounded-md border border-slate-200 bg-white text-slate-500 hover:text-indigo-600" title="Download" aria-label="Download artifact"><Download class="h-4 w-4" strokeWidth={1.9} /></a>
                                                                            {#if build.can_manage_artifacts}
                                                                                <button type="button" class="grid h-7 w-7 place-items-center rounded-md border border-slate-200 bg-white p-0 text-rose-500 shadow-none hover:text-rose-600" title="Delete" aria-label="Delete artifact" on:click={() => deleteArtifact(artifact)}><Trash2 class="h-4 w-4" strokeWidth={1.9} /></button>
                                                                            {/if}
                                                                        </div>
                                                                    </li>
                                                                {/each}
                                                            </ul>
                                                        {/if}

                                                        {#if build.can_manage_artifacts}
                                                            <form class="mt-3 rounded-xl border border-slate-200 bg-white p-3.5" on:submit|preventDefault={() => uploadArtifact(build)}>
                                                                <p class="mb-3 text-xs font-semibold text-slate-700">Attach a new artifact</p>
                                                                <div class="grid gap-3 sm:grid-cols-2">
                                                                    <label><span class="label">Name</span><input type="text" bind:value={artifactForm.name} placeholder="e.g. app-release.ipa" required />{#if artifactForm.errors.name}<span class="error">{artifactForm.errors.name}</span>{/if}</label>
                                                                    <label><span class="label">Metadata (JSON) <span class="font-normal text-slate-400">(optional)</span></span><input type="text" class="font-mono text-xs" bind:value={artifactForm.metadata} placeholder={'{ "channel": "internal" }'} />{#if artifactForm.errors.metadata}<span class="error">{artifactForm.errors.metadata}</span>{/if}</label>
                                                                </div>
                                                                <label class="mt-3 flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 transition hover:border-indigo-400 hover:bg-indigo-50/40">
                                                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white text-indigo-600 shadow-sm"><Upload class="h-4.5 w-4.5" strokeWidth={1.8} /></span>
                                                                    <span class="min-w-0"><strong class="block truncate text-xs text-slate-700">{artifactForm.file?.name || 'Choose a file'}</strong><small class="text-[10px] text-slate-400">The build output to store and share.</small></span>
                                                                    <input type="file" class="hidden" on:change={(e) => (artifactForm.file = e.currentTarget.files?.[0] ?? null)} />
                                                                </label>
                                                                {#if artifactForm.errors.file}<span class="error">{artifactForm.errors.file}</span>{/if}
                                                                <div class="mt-3 flex justify-end"><button type="submit" class="px-3 py-2 text-xs" disabled={artifactForm.processing || !artifactForm.file}>{artifactForm.processing ? 'Uploading…' : 'Upload artifact'}</button></div>
                                                            </form>
                                                        {/if}
                                                    </section>
                                                </td>
                                            </tr>
                                        {/if}
                                    {/each}
                                </tbody>
                            </table>
                        </div>
                    {:else}<div class="px-6 py-14 text-center"><p class="text-sm font-semibold">No builds yet</p><p class="mt-1 text-xs text-slate-500">Run a configured pipeline to create the first build.</p></div>{/if}
                </section>
            </div>
        {/if}
    </main>
</AppShell>

{#if showManualBuild}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeManualBuild()}>
        <form class="modal" on:submit|preventDefault={createManualBuild}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">Create manual build</h2><p class="mt-1 text-sm text-slate-500">This creates a build directly in App Manager and does not start GitHub Actions.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeManualBuild}>✕</button></div>
            <div class="space-y-5 p-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label><span class="label">App identifier <span class="font-normal text-slate-400">(optional)</span></span><select value={manualBuildForm.app_identifier_id} on:change={changeManualIdentifier}><option value="">No app identifier</option>{#each identifiers as identifier}<option value={identifier.id}>{identifier.platform.toUpperCase()} · {identifier.identifier}</option>{/each}</select>{#if manualBuildForm.errors.app_identifier_id}<span class="error">{manualBuildForm.errors.app_identifier_id}</span>{/if}</label>
                    <label><span class="label">Build profile</span><select bind:value={manualBuildForm.profile_type} required>{#each manualProfileOptions as profile}<option value={profile.value}>{profile.label}{manualBuildForm.app_identifier_id ? '' : ` · ${profile.platform}`}</option>{/each}</select>{#if manualBuildForm.errors.profile_type}<span class="error">{manualBuildForm.errors.profile_type}</span>{/if}</label>
                </div>
                <label><span class="label">Parameters (JSON)</span><textarea class="min-h-48 font-mono text-xs leading-5" bind:value={manualParametersText} spellcheck="false" placeholder={'{\n  "channel": "internal"\n}'}></textarea><span class="mt-1 block text-[10px] text-slate-400">These values are saved with the build and included in its webhook payloads.</span>{#if manualParametersError || manualBuildForm.errors.parameters}<span class="error">{manualParametersError || manualBuildForm.errors.parameters}</span>{/if}</label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeManualBuild}>Cancel</button><button disabled={manualBuildForm.processing || !manualBuildForm.profile_type}>{manualBuildForm.processing ? 'Creating…' : 'Create build'}</button></div>
        </form>
    </div>
{/if}

{#if launchingPipeline}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closePipelineBuild()}>
        <form class="modal" on:submit|preventDefault={runPipeline}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">Run {launchingPipeline.name}</h2><p class="mt-1 text-sm text-slate-500">{launchingPipeline.repository} · {launchingPipeline.workflow}</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closePipelineBuild}>✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Branch or tag</span><input bind:value={pipelineBuildForm.ref} required />{#if pipelineBuildForm.errors.ref}<span class="error">{pipelineBuildForm.errors.ref}</span>{/if}</label>
                <label><span class="label">Workflow inputs (JSON)</span><textarea class="min-h-52 font-mono text-xs leading-5" bind:value={pipelineParametersText} spellcheck="false" placeholder={'{}'}></textarea><span class="mt-1 block text-[10px] text-slate-400">Only inputs declared by the GitHub Actions workflow are accepted. Resolved values are saved with the build and included in webhooks.</span>{#if pipelineParametersError || firstFormError(pipelineBuildForm, 'inputs')}<span class="error">{pipelineParametersError || firstFormError(pipelineBuildForm, 'inputs')}</span>{/if}</label>
                {#if Object.keys(launchingPipeline.input_schema ?? {}).length}<div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"><p class="text-[10px] font-bold tracking-wider text-slate-500 uppercase">Declared inputs</p><div class="mt-2 flex flex-wrap gap-2">{#each Object.entries(launchingPipeline.input_schema) as [name, definition]}<span class="rounded-md bg-white px-2 py-1 font-mono text-[10px] text-slate-600 ring-1 ring-slate-200">{name}: {definition.type || 'string'}{definition.required ? ' · required' : ''}</span>{/each}</div></div>{/if}
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closePipelineBuild}>Cancel</button><button disabled={pipelineBuildForm.processing}>{pipelineBuildForm.processing ? 'Starting…' : 'Run build'}</button></div>
        </form>
    </div>
{/if}

{#if forcingBuild}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeForceStatus()}>
        <form class="modal max-w-lg" on:submit|preventDefault={forceBuildStatus}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">Force set build status</h2><p class="mt-1 text-sm text-slate-500">Use this when the manual runner will not report a final status.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeForceStatus}>✕</button></div>
            <div class="space-y-5 p-6">
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800">This bypasses runner confirmation and finishes the build immediately. A build completion webhook will be sent.</div>
                <label><span class="label">Final status</span><select bind:value={forceStatusForm.status}><option value="completed">Completed</option><option value="failed">Failed</option><option value="cancelled">Cancelled</option></select>{#if forceStatusForm.errors.status}<span class="error">{forceStatusForm.errors.status}</span>{/if}</label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeForceStatus}>Keep waiting</button><button class="bg-amber-600 hover:bg-amber-700" disabled={forceStatusForm.processing}>{forceStatusForm.processing ? 'Updating…' : 'Force status'}</button></div>
        </form>
    </div>
{/if}

{#if showIdentifier}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeIdentifier()}>
        <form class="modal" on:submit|preventDefault={saveIdentifier}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">{editingIdentifier ? 'Edit app identifier' : 'Add app identifier'}</h2><p class="mt-1 text-sm text-slate-500">{editingIdentifier ? 'Update this platform target.' : 'Connect a platform target to this project.'}</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeIdentifier}>✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Platform</span><select value={identifierForm.platform} on:change={changeIdentifierPlatform}><option value="ios">iOS</option><option value="android">Android</option></select></label>
                <label><span class="label">{identifierForm.platform === 'ios' ? 'Bundle identifier' : 'Package name'}</span><input bind:value={identifierForm.identifier} placeholder="com.company.product" required />{#if identifierForm.errors.identifier}<span class="error">{identifierForm.errors.identifier}</span>{/if}</label>
                <label><span class="label">Display name <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={identifierForm.name} placeholder={identifierForm.platform === 'ios' ? 'Atlas for iOS' : 'Atlas for Android'} />{#if identifierForm.errors.name}<span class="error">{identifierForm.errors.name}</span>{/if}</label>
                <div class="grid gap-4 {identifierForm.platform === 'android' ? 'sm:grid-cols-2' : ''}">
                    <label><span class="label">Version <span class="font-normal text-slate-400">(optional)</span></span><input bind:value={identifierForm.version} placeholder="1.0.0" />{#if identifierForm.errors.version}<span class="error">{identifierForm.errors.version}</span>{/if}</label>
                    {#if identifierForm.platform === 'android'}<label><span class="label">Version code <span class="font-normal text-slate-400">(optional)</span></span><input type="number" min="1" step="1" bind:value={identifierForm.version_code} placeholder="1" />{#if identifierForm.errors.version_code}<span class="error">{identifierForm.errors.version_code}</span>{/if}</label>{/if}
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeIdentifier}>Cancel</button><button disabled={identifierForm.processing}>{identifierForm.processing ? 'Saving…' : editingIdentifier ? 'Save changes' : 'Add identifier'}</button></div>
        </form>
    </div>
{/if}

{#if showCredential}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeCredential()}>
        <form class="modal" on:submit|preventDefault={saveCredential}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div><h2 class="text-lg font-bold">{editingCredential ? 'Edit credential' : 'Add credential'}</h2><p class="mt-1 text-sm text-slate-500">{editingCredential ? 'Update its attachment, metadata, secure fields, or file.' : 'Choose where the build runs, then upload the matching signing file.'}</p></div>
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
                                {#if editingCredential && field.input === 'password' && !credentialFieldRequired(field)}<span class="mt-1 block text-[10px] text-slate-400">Already configured. Enter a new value only to replace it.</span>{/if}
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
                        <span class="min-w-0"><strong class="block truncate text-xs text-slate-700">{credentialForm.file?.name || (editingCredential?.file && !credentialTypeChanged ? `Keep ${editingCredential.file.name}` : 'Choose a file')}</strong><small class="text-[10px] text-slate-400">{credentialTypeChanged ? 'Changing the type requires a matching replacement file.' : selectedSchema?.file.hint || 'Credential file'}</small></span>
                        <input type="file" class="hidden" accept={selectedSchema?.file.accept} on:change={pickCredentialFile} />
                    </span>
                    {#if credentialForm.errors.file}<span class="error">{credentialForm.errors.file}</span>{/if}
                </label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeCredential}>Cancel</button><button disabled={credentialForm.processing}>{credentialForm.processing ? 'Saving…' : editingCredential ? 'Save changes' : 'Add credential'}</button></div>
        </form>
    </div>
{/if}

{#if showEdit}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeEdit()}>
        <form class="modal" on:submit|preventDefault={saveEdit}>
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                <div><h2 class="text-lg font-bold">Edit application</h2><p class="mt-1 text-sm text-slate-500">Update the project name and description.</p></div>
                <button type="button" class="ghost -mr-2 p-2" on:click={closeEdit}>✕</button>
            </div>
            <div class="space-y-5 p-6">
                <label><span class="label">Name</span><input bind:value={editForm.name} required />{#if editForm.errors.name}<span class="error">{editForm.errors.name}</span>{/if}</label>
                <label><span class="label">Description <span class="font-normal text-slate-400">(optional)</span></span><textarea class="min-h-24" bind:value={editForm.description} placeholder="What is this project?"></textarea>{#if editForm.errors.description}<span class="error">{editForm.errors.description}</span>{/if}</label>
                <div class="rounded-xl border border-rose-200 bg-rose-50/60 p-4">
                    {#if confirmingDelete}
                        <p class="text-xs font-semibold text-rose-800">Delete this application? This can't be undone.</p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" class="secondary px-3 py-2 text-xs" on:click={() => confirmingDelete = false}>Cancel</button>
                            <button type="button" class="bg-rose-600 px-3 py-2 text-xs text-white hover:bg-rose-700" disabled={deleting} on:click={deleteApp}>Confirm delete</button>
                        </div>
                    {:else}
                        <div class="flex items-center justify-between gap-3">
                            <div><p class="text-xs font-bold text-rose-800">Danger zone</p><p class="mt-0.5 text-[11px] text-rose-700/80">Permanently delete this application and its data.</p></div>
                            <button type="button" class="border border-rose-300 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100" on:click={() => confirmingDelete = true}>Delete application</button>
                        </div>
                    {/if}
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-4"><button type="button" class="secondary" on:click={closeEdit}>Cancel</button><button disabled={editForm.processing}>{editForm.processing ? 'Saving…' : 'Save changes'}</button></div>
        </form>
    </div>
{/if}
