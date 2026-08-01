<script>
    import { useForm } from '@inertiajs/svelte';
    import Select from '../../../Components/Select.svelte';

    export let open = false;
    export let integration = null;
    export let pipeline = null;
    export let apps = [];
    export let profileTypes = [];
    export let reportingModes = ['automatic', 'manual'];
    export let fixedApp = null;
    export let onClose = () => {};

    let initializedKey = null;
    let repositories = [];
    let workflows = [];
    let refs = [];
    let discoveryError = '';
    let loadingRepositories = false;
    let loadingWorkflows = false;
    let loadingRefs = false;
    let repositoryRequest = 0;

    const pipelineForm = useForm({
        name: '',
        mobile_app_id: '',
        mobile_app_ids: [],
        app_identifier_id: '',
        profile_type: profileTypes[0] ?? '',
        repository_full_name: '',
        workflow_id: '',
        default_ref: '',
        input_defaults: {},
        enabled: true,
        reporting_mode: 'automatic',
        build_id_input: '',
    });

    $: availableApps = fixedApp ? [fixedApp] : apps;
    $: selectedAppIds = fixedApp
        ? [fixedApp.id]
        : (pipeline ? [pipelineForm.mobile_app_id].filter(Boolean) : pipelineForm.mobile_app_ids);
    $: selectedApp = selectedAppIds.length === 1
        ? availableApps.find((app) => app.id === selectedAppIds[0])
        : null;
    $: activeRepository = repositories.find((repository) => repository.fullName === pipelineForm.repository_full_name);
    $: selectedWorkflow = workflows.find((workflow) => String(workflow.id) === String(pipelineForm.workflow_id));
    $: manualBuildInputs = Object.entries(selectedWorkflow?.inputs ?? {})
        .filter(([, definition]) => (definition.type ?? 'string') === 'string')
        .map(([name]) => name);
    $: modalKey = open && integration
        ? `${integration.id}:${pipeline?.id ?? 'new'}:${fixedApp?.id ?? 'all'}`
        : null;
    $: if (modalKey && modalKey !== initializedKey) {
        initializedKey = modalKey;
        initialize(integration, pipeline);
    }
    $: if (!open && initializedKey !== null) initializedKey = null;

    const getJson = async (url) => {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(response.status === 422 ? 'Check the selected repository.' : 'CI/CD discovery failed.');
        return (await response.json()).data ?? [];
    };

    const withRepository = (url, repository) => {
        const target = new URL(url, window.location.origin);
        target.searchParams.set('repository', repository);
        return target.toString();
    };

    const workflowDefaults = (workflow) => {
        pipelineForm.input_defaults = Object.fromEntries(Object.entries(workflow?.inputs ?? {})
            .filter(([, definition]) => definition.default !== undefined && definition.default !== null)
            .map(([name, definition]) => [name, definition.default]));
        const stringInputs = Object.entries(workflow?.inputs ?? {})
            .filter(([, definition]) => (definition.type ?? 'string') === 'string')
            .map(([name]) => name);
        pipelineForm.build_id_input = stringInputs.includes('app_manager_build_id')
            ? 'app_manager_build_id'
            : (stringInputs[0] ?? '');
    };

    const setInputValue = (name, definition, event) => {
        let value = event.detail ?? event.currentTarget.value;
        if (definition.type === 'boolean') value = event.currentTarget.checked;
        if (definition.type === 'number') value = value === '' ? '' : Number(value);
        if (definition.type === 'array') {
            try { value = JSON.parse(value); } catch (_) { /* Keep invalid JSON visible for server validation. */ }
        }
        pipelineForm.input_defaults = { ...pipelineForm.input_defaults, [name]: value };
    };

    const togglePipelineApp = (appId) => {
        pipelineForm.mobile_app_ids = pipelineForm.mobile_app_ids.includes(appId)
            ? pipelineForm.mobile_app_ids.filter((id) => id !== appId)
            : [...pipelineForm.mobile_app_ids, appId];
        pipelineForm.app_identifier_id = '';
    };

    const selectAllPipelineApps = () => {
        pipelineForm.mobile_app_ids = availableApps.map((app) => app.id);
        pipelineForm.app_identifier_id = '';
    };

    const clearPipelineApps = () => {
        pipelineForm.mobile_app_ids = [];
        pipelineForm.app_identifier_id = '';
    };

    const initialize = async (currentIntegration, currentPipeline = null) => {
        const request = ++repositoryRequest;
        repositories = [];
        workflows = [];
        refs = [];
        discoveryError = '';
        pipelineForm.reset();
        pipelineForm.clearErrors();
        pipelineForm.mobile_app_id = currentPipeline?.mobile_app_id ?? fixedApp?.id ?? '';
        pipelineForm.mobile_app_ids = currentPipeline
            ? []
            : (fixedApp?.id ? [fixedApp.id] : (availableApps[0]?.id ? [availableApps[0].id] : []));
        pipelineForm.app_identifier_id = '';
        pipelineForm.profile_type = profileTypes[0] ?? '';
        pipelineForm.enabled = true;
        pipelineForm.reporting_mode = 'automatic';
        pipelineForm.build_id_input = '';
        loadingRepositories = true;

        try {
            const discovered = await getJson(currentIntegration.repositories_url);
            if (request !== repositoryRequest) return;
            repositories = fixedApp && currentIntegration.provider === 'expo'
                ? discovered.filter((item) => item.mobileAppId === fixedApp.id)
                : discovered;

            if (repositories.length) {
                const repository = repositories.find((item) => item.fullName === currentPipeline?.repository_full_name) ?? repositories[0];
                pipelineForm.repository_full_name = repository.fullName;
                loadingRepositories = false;
                loadRepository(currentIntegration, repository.fullName, currentPipeline);
            } else if (currentIntegration.provider === 'expo') {
                discoveryError = fixedApp
                    ? 'This application is not linked to an Expo project with EAS Workflows.'
                    : 'No linked Expo applications are available for EAS Workflows.';
            }
        } catch (error) {
            if (request === repositoryRequest) discoveryError = error.message;
        } finally {
            if (request === repositoryRequest) loadingRepositories = false;
        }
    };

    const loadRepository = async (currentIntegration, repository, currentPipeline = null) => {
        const request = ++repositoryRequest;
        discoveryError = '';
        pipelineForm.repository_full_name = repository;
        workflows = [];
        refs = [];
        pipelineForm.workflow_id = '';
        pipelineForm.default_ref = '';
        loadingWorkflows = true;
        loadingRefs = true;
        const repositoryItem = repositories.find((item) => item.fullName === repository);

        if (currentIntegration.provider === 'expo' && repositoryItem?.mobileAppId) {
            pipelineForm.mobile_app_id = repositoryItem.mobileAppId;
            pipelineForm.mobile_app_ids = [repositoryItem.mobileAppId];
            pipelineForm.app_identifier_id = currentPipeline?.app_identifier_id ?? '';
            pipelineForm.reporting_mode = 'automatic';
        }

        const workflowsPromise = getJson(withRepository(currentIntegration.workflows_url, repository))
            .then((items) => {
                if (request !== repositoryRequest) return;
                workflows = items;
                const workflow = items.find((item) => String(item.id) === String(currentPipeline?.workflow_id)) ?? items[0];
                pipelineForm.workflow_id = workflow?.id ?? '';
                pipelineForm.name = currentPipeline?.name ?? workflow?.name ?? '';
                workflowDefaults(workflow);
                if (currentPipeline) {
                    pipelineForm.input_defaults = { ...(currentPipeline.input_defaults ?? {}) };
                    pipelineForm.build_id_input = currentPipeline.build_id_input ?? '';
                }
            })
            .catch((error) => {
                if (request === repositoryRequest) discoveryError = error.message;
            })
            .finally(() => {
                if (request === repositoryRequest) loadingWorkflows = false;
            });

        const refsPromise = getJson(withRepository(currentIntegration.refs_url, repository))
            .then((items) => {
                if (request !== repositoryRequest) return;
                refs = items;
                pipelineForm.default_ref = items.some((ref) => ref.name === currentPipeline?.default_ref)
                    ? currentPipeline.default_ref
                    : (items.find((ref) => ref.type === 'branch')?.name ?? items[0]?.name ?? '');
            })
            .catch((error) => {
                if (request === repositoryRequest) discoveryError = error.message;
            })
            .finally(() => {
                if (request === repositoryRequest) loadingRefs = false;
            });

        if (currentPipeline) {
            pipelineForm.mobile_app_id = fixedApp?.id ?? currentPipeline.mobile_app_id;
            pipelineForm.mobile_app_ids = [];
            pipelineForm.app_identifier_id = currentPipeline.app_identifier_id ?? '';
            pipelineForm.profile_type = currentPipeline.profile_type;
            pipelineForm.enabled = currentPipeline.enabled;
            pipelineForm.reporting_mode = currentPipeline.reporting_mode;
        }

        await Promise.allSettled([workflowsPromise, refsPromise]);
    };

    const chooseWorkflow = (event) => {
        pipelineForm.workflow_id = event.detail;
        const workflow = workflows.find((item) => String(item.id) === String(pipelineForm.workflow_id));
        if (!pipelineForm.name || workflows.some((item) => item.name === pipelineForm.name)) pipelineForm.name = workflow?.name ?? '';
        workflowDefaults(workflow);
    };

    const close = () => {
        repositoryRequest += 1;
        onClose();
    };

    const savePipeline = () => {
        const options = { preserveScroll: true, onSuccess: close };
        if (pipeline) pipelineForm.patch(pipeline.update_url, options);
        else pipelineForm.post(integration.pipelines_store_url, options);
    };
</script>

{#if open && integration}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && close()}>
        <form class="modal max-w-2xl" on:submit|preventDefault={savePipeline}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div>
                    <h2 class="text-lg font-bold">{pipeline ? `Edit ${integration.provider_name ?? 'CI/CD'} pipeline` : `Configure ${integration.provider_name ?? 'CI/CD'} pipeline`}</h2>
                    <p class="mt-1 text-sm text-muted">{pipeline ? 'Update the provider pipeline binding.' : (fixedApp ? `Bind a provider pipeline to ${fixedApp.name}.` : 'Bind a provider pipeline to one or more applications.')}</p>
                </div>
                <button type="button" class="ghost p-2" aria-label="Close pipeline form" on:click={close}>✕</button>
            </div>

            <div class="space-y-5 p-6">
                {#if discoveryError}<div class="rounded-md bg-danger/10 px-4 py-3 text-xs font-medium text-danger">{discoveryError}</div>{/if}

                <label class="block">
                    <span class="label">{integration.provider === 'expo' ? 'Expo project' : 'Repository'}</span>
                    <Select bind:value={pipelineForm.repository_full_name} options={loadingRepositories ? [{ value: '', label: 'Loading repositories…' }] : repositories.map((repository) => ({ value: repository.fullName, label: `${repository.fullName}${repository.sourceRepository ? ` · ${repository.sourceRepository}` : ''}` }))} ariaLabel="Repository" disabled={loadingRepositories || !repositories.length} on:change={(event) => loadRepository(integration, event.detail)} />
                    {#if pipelineForm.errors.repository_full_name}<span class="error">{pipelineForm.errors.repository_full_name}</span>{/if}
                </label>

                {#if integration.provider === 'expo' && activeRepository?.readiness !== 'ready'}
                    <div class="rounded-md border border-warn-fill bg-warn-text/10 px-4 py-3 text-xs leading-5 text-warn-fill">
                        <strong class="block">{activeRepository?.readiness === 'github_not_connected' ? 'Connect GitHub in Expo' : 'Add an EAS Workflow'}</strong>
                        {activeRepository?.readinessMessage}
                        {#if activeRepository?.url}<a href={activeRepository.url} target="_blank" rel="noreferrer" class="mt-2 inline-block font-semibold underline">Open Expo project</a>{/if}
                    </div>
                {/if}

                <label class="block">
                    <span class="label">{integration.provider === 'expo' ? 'EAS Workflow' : 'Provider pipeline'}</span>
                    <Select bind:value={pipelineForm.workflow_id} options={loadingWorkflows ? [{ value: '', label: 'Loading pipelines…' }] : workflows.map((workflow) => ({ value: workflow.id, label: `${workflow.name} · ${workflow.path}` }))} ariaLabel="Provider pipeline" disabled={loadingWorkflows || !workflows.length} on:change={chooseWorkflow} />
                    {#if pipelineForm.errors.workflow_id}<span class="error">{pipelineForm.errors.workflow_id}</span>{/if}
                </label>

                <label class="block">
                    <span class="label">Pipeline name</span>
                    <input bind:value={pipelineForm.name} required />
                    {#if pipelineForm.errors.name}<span class="error">{pipelineForm.errors.name}</span>{/if}
                </label>

                {#if fixedApp || integration.provider === 'expo'}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="rounded-md border border-border bg-surface-sunken px-4 py-3">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-faint">Linked application</span>
                            <p class="mt-1 text-sm font-semibold text-text">{selectedApp?.name ?? (fixedApp ? fixedApp.name : 'Select an Expo project')}</p>
                            <p class="mt-1 text-xs text-muted">This pipeline will only be available for this application.</p>
                            {#if pipelineForm.errors.mobile_app_id}<span class="error">{pipelineForm.errors.mobile_app_id}</span>{/if}
                        </div>
                        <label class="block">
                            <span class="label">App identifier</span>
                            <Select bind:value={pipelineForm.app_identifier_id} options={[{ value: '', label: 'All targets' }, ...(selectedApp?.identifiers ?? []).map((identifier) => ({ value: identifier.id, label: `${identifier.name || identifier.identifier} · ${identifier.platform}` }))]} ariaLabel="App identifier" />
                            {#if pipelineForm.errors.app_identifier_id}<span class="error">{pipelineForm.errors.app_identifier_id}</span>{/if}
                        </label>
                    </div>
                {:else if pipeline}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block"><span class="label">Application</span><Select bind:value={pipelineForm.mobile_app_id} options={availableApps.map((app) => ({ value: app.id, label: app.name }))} ariaLabel="Application" on:change={() => pipelineForm.app_identifier_id = ''} /></label>
                        <label class="block"><span class="label">App identifier</span><Select bind:value={pipelineForm.app_identifier_id} options={[{ value: '', label: 'All targets' }, ...(selectedApp?.identifiers ?? []).map((identifier) => ({ value: identifier.id, label: `${identifier.name || identifier.identifier} · ${identifier.platform}` }))]} ariaLabel="App identifier" /></label>
                    </div>
                {:else}
                    <fieldset>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <legend class="label mb-0">Applications</legend>
                            <div class="flex items-center gap-3 text-xs">
                                <button type="button" class="ghost p-0 font-semibold text-brand shadow-none" on:click={selectAllPipelineApps}>Select all</button>
                                <button type="button" class="ghost p-0 font-semibold text-muted shadow-none" on:click={clearPipelineApps}>Clear</button>
                            </div>
                        </div>
                        <div class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-border bg-surface-raised p-2">
                            {#each availableApps as app}
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition hover:bg-surface-sunken">
                                    <input class="h-4 w-4 shrink-0 accent-brand shadow-none" type="checkbox" checked={pipelineForm.mobile_app_ids.includes(app.id)} on:change={() => togglePipelineApp(app.id)} />
                                    <span class="min-w-0 flex-1 truncate font-medium text-text">{app.name}</span>
                                </label>
                            {/each}
                        </div>
                        <p class="mt-2 text-xs text-muted">{pipelineForm.mobile_app_ids.length} application{pipelineForm.mobile_app_ids.length === 1 ? '' : 's'} selected. A separate runnable binding will be created for each application.</p>
                        {#if pipelineForm.errors.mobile_app_ids}<span class="error">{pipelineForm.errors.mobile_app_ids}</span>{/if}
                    </fieldset>
                    <label class="block">
                        <span class="label">App identifier</span>
                        <Select bind:value={pipelineForm.app_identifier_id} options={[{ value: '', label: pipelineForm.mobile_app_ids.length > 1 ? 'All targets (required for multiple applications)' : 'All targets' }, ...(selectedApp?.identifiers ?? []).map((identifier) => ({ value: identifier.id, label: `${identifier.name || identifier.identifier} · ${identifier.platform}` }))]} ariaLabel="App identifier" disabled={pipelineForm.mobile_app_ids.length !== 1} />
                        {#if pipelineForm.mobile_app_ids.length > 1}<span class="mt-1 block text-xs text-muted">Specific identifiers can only be selected when configuring one application.</span>{/if}
                        {#if pipelineForm.errors.app_identifier_id}<span class="error">{pipelineForm.errors.app_identifier_id}</span>{/if}
                    </label>
                {/if}

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block"><span class="label">Build profile</span><Select bind:value={pipelineForm.profile_type} options={profileTypes.map((profile) => ({ value: profile, label: profile }))} ariaLabel="Build profile" /></label>
                    <label class="block"><span class="label">Default branch or tag</span><Select bind:value={pipelineForm.default_ref} options={loadingRefs ? [{ value: '', label: 'Loading branches and tags…' }] : refs.map((ref) => ({ value: ref.name, label: `${ref.name} · ${ref.type}` }))} ariaLabel="Default branch or tag" disabled={loadingRefs || !refs.length} /></label>
                </div>

                {#if integration.provider === 'expo'}
                    <div class="rounded-md border border-brand-soft-line bg-brand-soft px-4 py-3 text-xs leading-5 text-brand"><strong class="block">Automatic reporting</strong>App Manager polls the EAS Workflow run and synchronizes its status and job summary.</div>
                {:else}
                    <fieldset>
                        <legend class="label">Build status and logs</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            {#each reportingModes as mode}
                                <label class="flex cursor-pointer items-start gap-3 rounded-md border p-4 transition {pipelineForm.reporting_mode === mode ? 'border-brand bg-brand-soft ring-1 ring-brand' : 'border-border bg-surface-raised hover:border-control'}">
                                    <input class="mt-0.5 h-4 w-4 shrink-0 accent-brand shadow-none focus:ring-2 focus:ring-brand/20" type="radio" bind:group={pipelineForm.reporting_mode} value={mode} />
                                    <span class="min-w-0"><strong class="block text-sm capitalize text-text">{mode}</strong><span class="mt-1 block text-xs leading-5 text-muted">{mode === 'automatic' ? `App Manager polls ${integration.provider_name ?? 'the CI provider'} and downloads its logs.` : 'Your runner updates the build and appends logs through the App Manager API.'}</span></span>
                                </label>
                            {/each}
                        </div>
                        {#if pipelineForm.errors.reporting_mode}<span class="error">{pipelineForm.errors.reporting_mode}</span>{/if}
                    </fieldset>
                {/if}

                {#if pipelineForm.reporting_mode === 'manual'}
                    <label class="block"><span class="label">Build ID pipeline input</span><Select bind:value={pipelineForm.build_id_input} options={[{ value: '', label: 'Select a string input', disabled: true }, ...manualBuildInputs.map((name) => ({ value: name, label: name }))]} ariaLabel="Build ID pipeline input" required /><span class="mt-1 block text-xs text-muted">App Manager injects the build UUID into this input. Pass it to your runner as APP_MANAGER_BUILD_ID.</span>{#if pipelineForm.errors.build_id_input}<span class="error">{pipelineForm.errors.build_id_input}</span>{/if}</label>
                {/if}

                {#if selectedWorkflow && Object.keys(selectedWorkflow.inputs ?? {}).length}
                    <fieldset>
                        <legend class="label">Pipeline inputs</legend>
                        <div class="space-y-3 rounded-md border border-border p-4">
                            {#each Object.entries(selectedWorkflow.inputs) as [name, definition]}
                                {#if pipelineForm.reporting_mode !== 'manual' || name !== pipelineForm.build_id_input}
                                    <label class="block">
                                        <span class="mb-1 block text-xs font-semibold">{name}{#if definition.required}<span class="text-danger"> *</span>{/if}</span>
                                        {#if definition.options?.length}
                                            <Select value={pipelineForm.input_defaults[name] ?? ''} options={[{ value: '', label: 'Select a value', disabled: true }, ...definition.options.map((option) => ({ value: option, label: option }))]} ariaLabel={name} on:change={(event) => setInputValue(name, definition, event)} />
                                        {:else if definition.type === 'boolean'}
                                            <input class="h-4 w-4" type="checkbox" checked={pipelineForm.input_defaults[name] ?? false} on:change={(event) => setInputValue(name, definition, event)} />
                                        {:else if definition.type === 'array'}
                                            <textarea rows="3" on:change={(event) => setInputValue(name, definition, event)} placeholder={definition.description ?? 'JSON array'}>{JSON.stringify(pipelineForm.input_defaults[name] ?? [])}</textarea>
                                        {:else}
                                            <input type={definition.type === 'number' ? 'number' : 'text'} value={pipelineForm.input_defaults[name] ?? ''} on:input={(event) => setInputValue(name, definition, event)} placeholder={definition.description ?? ''} />
                                        {/if}
                                    </label>
                                {/if}
                            {/each}
                        </div>
                    </fieldset>
                {/if}
            </div>

            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4">
                <button type="button" class="secondary" on:click={close}>Cancel</button>
                <button disabled={pipelineForm.processing || loadingRepositories || loadingWorkflows || loadingRefs || !workflows.length || !refs.length || (!pipeline && !fixedApp && !pipelineForm.mobile_app_ids.length)}>{pipelineForm.processing ? 'Saving…' : (!pipeline && !fixedApp && pipelineForm.mobile_app_ids.length > 1 ? `Save for ${pipelineForm.mobile_app_ids.length} apps` : 'Save pipeline')}</button>
            </div>
        </form>
    </div>
{/if}
