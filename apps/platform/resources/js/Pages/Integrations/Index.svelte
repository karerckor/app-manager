<script>
    import { Link, router, useForm } from '@inertiajs/svelte';
    import AppShell from '../../Components/AppShell.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let githubEnabled = false;
    export let connectGitHubUrl;
    export let integrations = [];
    export let apps = [];
    export let profileTypes = [];
    export let storeWebhookUrl;
    export let webhooksEnabled = false;
    export let webhookEventTypes = [];
    export let webhookEndpoints = [];
    export let newWebhookSecret = null;

    let tab = 'ci_cd';
    let showPipeline = false;
    let activeIntegration = null;
    let repositories = [];
    let workflows = [];
    let refs = [];
    let discoveryError = '';
    let discovering = false;
    let showWebhook = false;
    let editingEndpoint = null;

    const pipelineForm = useForm({
        name: '',
        mobile_app_id: apps[0]?.id ?? '',
        app_identifier_id: '',
        profile_type: profileTypes[0] ?? '',
        repository_full_name: '',
        workflow_id: '',
        default_ref: '',
        input_defaults: {},
        enabled: true,
    });
    const webhookForm = useForm({
        name: '',
        url: '',
        events: webhookEventTypes.length ? [webhookEventTypes[0]] : [],
        enabled: true,
    });

    $: selectedApp = apps.find((app) => app.id === pipelineForm.mobile_app_id);
    $: selectedWorkflow = workflows.find((workflow) => String(workflow.id) === String(pipelineForm.workflow_id));

    const getJson = async (url) => {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(response.status === 422 ? 'Check the selected repository.' : 'GitHub discovery failed.');
        return (await response.json()).data ?? [];
    };
    const withRepository = (url, repository) => {
        const target = new URL(url, window.location.origin);
        target.searchParams.set('repository', repository);
        return target.toString();
    };
    const openPipeline = async (integration) => {
        activeIntegration = integration;
        repositories = [];
        workflows = [];
        refs = [];
        discoveryError = '';
        pipelineForm.reset();
        pipelineForm.mobile_app_id = apps[0]?.id ?? '';
        pipelineForm.profile_type = profileTypes[0] ?? '';
        pipelineForm.enabled = true;
        showPipeline = true;
        discovering = true;
        try {
            repositories = await getJson(integration.repositories_url);
            if (repositories.length) {
                pipelineForm.repository_full_name = repositories[0].fullName;
                await loadRepository(integration, repositories[0].fullName);
            }
        } catch (error) {
            discoveryError = error.message;
        } finally {
            discovering = false;
        }
    };
    const loadRepository = async (integration, repository) => {
        discovering = true;
        discoveryError = '';
        pipelineForm.repository_full_name = repository;
        try {
            [workflows, refs] = await Promise.all([
                getJson(withRepository(integration.workflows_url, repository)),
                getJson(withRepository(integration.refs_url, repository)),
            ]);
            pipelineForm.workflow_id = workflows[0]?.id ?? '';
            pipelineForm.default_ref = refs.find((ref) => ref.type === 'branch')?.name ?? refs[0]?.name ?? '';
            pipelineForm.name = workflows[0]?.name ?? '';
            pipelineForm.input_defaults = Object.fromEntries(Object.entries(workflows[0]?.inputs ?? {})
                .filter(([, definition]) => definition.default !== undefined && definition.default !== null)
                .map(([name, definition]) => [name, definition.default]));
        } catch (error) {
            workflows = [];
            refs = [];
            discoveryError = error.message;
        } finally {
            discovering = false;
        }
    };
    const chooseWorkflow = (event) => {
        pipelineForm.workflow_id = event.currentTarget.value;
        const workflow = workflows.find((item) => String(item.id) === String(pipelineForm.workflow_id));
        if (!pipelineForm.name || workflows.some((item) => item.name === pipelineForm.name)) pipelineForm.name = workflow?.name ?? '';
        pipelineForm.input_defaults = Object.fromEntries(Object.entries(workflow?.inputs ?? {})
            .filter(([, definition]) => definition.default !== undefined && definition.default !== null)
            .map(([name, definition]) => [name, definition.default]));
    };
    const savePipeline = () => pipelineForm.post(activeIntegration.pipelines_store_url, {
        preserveScroll: true,
        onSuccess: () => showPipeline = false,
    });
    const runPipeline = (pipeline) => router.post(pipeline.run_url, {
        request_uuid: crypto.randomUUID(),
        ref: pipeline.default_ref,
        inputs: pipeline.input_defaults ?? {},
    }, { preserveScroll: true });
    const syncIntegration = (integration) => router.post(integration.sync_url, {}, { preserveScroll: true });
    const disconnectIntegration = (integration) => {
        if (confirm(`Disconnect ${integration.name}? Existing build history will be preserved.`)) {
            router.delete(integration.disconnect_url, { preserveScroll: true });
        }
    };
    const deletePipeline = (pipeline) => {
        if (confirm(`Remove pipeline “${pipeline.name}”?`)) router.delete(pipeline.destroy_url, { preserveScroll: true });
    };

    const openCreateWebhook = () => {
        editingEndpoint = null;
        webhookForm.reset();
        webhookForm.events = webhookEventTypes.length ? [webhookEventTypes[0]] : [];
        webhookForm.enabled = true;
        showWebhook = true;
    };
    const openEditWebhook = (endpoint) => {
        editingEndpoint = endpoint;
        webhookForm.name = endpoint.name;
        webhookForm.url = endpoint.url;
        webhookForm.events = [...endpoint.events];
        webhookForm.enabled = endpoint.status === 'active';
        webhookForm.clearErrors();
        showWebhook = true;
    };
    const toggleEvent = (eventType) => {
        webhookForm.events = webhookForm.events.includes(eventType)
            ? webhookForm.events.filter((item) => item !== eventType)
            : [...webhookForm.events, eventType];
    };
    const saveWebhook = () => {
        const options = { preserveScroll: true, onSuccess: () => showWebhook = false };
        if (editingEndpoint) webhookForm.patch(editingEndpoint.update_url, options);
        else webhookForm.post(storeWebhookUrl, options);
    };
    const testWebhook = (endpoint) => router.post(endpoint.test_url, {}, { preserveScroll: true });
    const rotateSecret = (endpoint) => {
        if (confirm('Rotate the signing secret? The old secret will stop working immediately.')) {
            router.post(endpoint.rotate_secret_url, {}, { preserveScroll: true });
        }
    };
    const deleteWebhook = (endpoint) => {
        if (confirm(`Delete webhook “${endpoint.name}”?`)) router.delete(endpoint.destroy_url, { preserveScroll: true });
    };
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : 'Never';
    const statusClass = (status) => ({
        active: 'bg-emerald-50 text-emerald-700',
        delivered: 'bg-emerald-50 text-emerald-700',
        retry_scheduled: 'bg-amber-50 text-amber-700',
        disabled: 'bg-amber-50 text-amber-700',
        exhausted: 'bg-rose-50 text-rose-700',
        disconnected: 'bg-slate-100 text-slate-600',
    }[status] ?? 'bg-slate-100 text-slate-600');
</script>

<svelte:head><title>Integrations · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="integrations">
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <header class="mb-8"><p class="eyebrow mb-2">Workspace settings</p><h1 class="text-3xl font-bold tracking-tight">Integrations</h1><p class="mt-2 text-sm text-slate-500">Run external pipelines and deliver signed lifecycle events.</p></header>

        {#if flash?.status}<div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{flash.status}</div>{/if}
        {#if newWebhookSecret}<div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5"><p class="text-sm font-bold text-amber-900">Copy this signing secret now</p><p class="mt-1 text-xs text-amber-700">It will not be shown again.</p><code class="mt-3 block overflow-x-auto rounded-xl bg-slate-950 px-4 py-3 text-xs text-amber-100">{newWebhookSecret.secret}</code></div>{/if}

        <div class="mb-7 flex gap-1 border-b border-slate-200">
            <button class="rounded-none border-b-2 px-4 py-3 shadow-none {tab === 'ci_cd' ? 'border-indigo-600 bg-transparent text-indigo-700' : 'border-transparent bg-transparent text-slate-500 hover:bg-transparent'}" on:click={() => tab = 'ci_cd'}>CI/CD</button>
            <button class="rounded-none border-b-2 px-4 py-3 shadow-none {tab === 'webhooks' ? 'border-indigo-600 bg-transparent text-indigo-700' : 'border-transparent bg-transparent text-slate-500 hover:bg-transparent'}" on:click={() => tab = 'webhooks'}>Webhooks</button>
        </div>

        {#if tab === 'ci_cd'}
            <section class="space-y-5">
                <div class="card flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4"><span class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-950 text-white"><svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor"><path d="M12 .7a11.5 11.5 0 0 0-3.6 22.4c.6.1.8-.2.8-.5v-2.2c-3.3.7-4-1.4-4-1.4-.5-1.4-1.3-1.8-1.3-1.8-1.1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1.1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.8-1.6-2.7-.3-5.5-1.3-5.5-5.9 0-1.3.5-2.4 1.2-3.2-.1-.3-.5-1.5.1-3.2 0 0 1-.3 3.3 1.2a11.4 11.4 0 0 1 6 0C16.6 4.7 17.6 5 17.6 5c.6 1.7.2 2.9.1 3.2.8.8 1.2 1.9 1.2 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.2c0 .3.2.6.8.5A11.5 11.5 0 0 0 12 .7Z"/></svg></span><div><h2 class="text-sm font-bold">GitHub Actions</h2><p class="mt-1 text-xs text-slate-500">Discover workflow_dispatch pipelines and run builds.</p></div></div>
                    {#if githubEnabled}<Link href={connectGitHubUrl} class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Connect GitHub</Link>{:else}<span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500">Not configured by operator</span>{/if}
                </div>

                {#each integrations as integration}
                    <article class="card p-0">
                        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 px-5 py-4"><div><div class="flex items-center gap-2"><h2 class="text-sm font-bold">{integration.name}</h2><span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {statusClass(integration.status)}">{integration.status}</span></div><p class="mt-1 text-xs text-slate-500">{integration.account_name} · {integration.pipelines_count} configured pipeline{integration.pipelines_count === 1 ? '' : 's'}</p></div><div class="flex gap-2"><button class="secondary px-3 py-2 text-xs" on:click={() => syncIntegration(integration)}>Sync</button><button class="px-3 py-2 text-xs" disabled={integration.status !== 'active' || !apps.length} on:click={() => openPipeline(integration)}>Configure pipeline</button><button class="ghost px-3 py-2 text-xs text-rose-600" on:click={() => disconnectIntegration(integration)}>Disconnect</button></div></div>
                        {#if integration.pipelines.length}
                            <div class="divide-y divide-slate-100">
                                {#each integration.pipelines as pipeline}
                                    <div class="grid gap-4 px-5 py-4 md:grid-cols-[minmax(0,1fr)_minmax(180px,0.5fr)_auto] md:items-center"><div><div class="flex items-center gap-2"><p class="text-sm font-semibold">{pipeline.name}</p>{#if !pipeline.enabled}<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500">Disabled</span>{/if}</div><p class="mt-1 text-xs text-slate-500">{pipeline.repository_full_name} · {pipeline.workflow_name} · {pipeline.default_ref}</p></div><div class="text-xs"><p class="font-medium">{pipeline.app_name}</p><p class="mt-1 text-slate-500">{pipeline.target_name || 'All targets'} · {pipeline.profile_type}</p></div><div class="flex justify-end gap-2"><button class="px-3 py-2 text-xs" disabled={!pipeline.enabled} on:click={() => runPipeline(pipeline)}>Run build</button><button class="ghost px-2 py-2 text-xs text-rose-600" on:click={() => deletePipeline(pipeline)}>Remove</button></div></div>
                                {/each}
                            </div>
                        {:else}<div class="px-5 py-9 text-center text-sm text-slate-500">No pipelines configured yet.</div>{/if}
                    </article>
                {/each}
            </section>
        {:else}
            <section class="space-y-5">
                <div class="flex flex-wrap items-end justify-between gap-4"><div><h2 class="text-lg font-bold">Outbound endpoints</h2><p class="mt-1 text-sm text-slate-500">Every request is signed with HMAC-SHA256 and retried on failure.</p></div>{#if webhooksEnabled}<button on:click={openCreateWebhook}>New endpoint</button>{:else}<span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500">Not enabled by operator</span>{/if}</div>
                {#if webhookEndpoints.length}
                    {#each webhookEndpoints as endpoint}
                        <article class="card p-0"><div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-4"><div><div class="flex items-center gap-2"><h3 class="text-sm font-bold">{endpoint.name}</h3><span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {statusClass(endpoint.status)}">{endpoint.status}</span></div><code class="mt-1.5 block max-w-xl truncate text-xs text-slate-500">{endpoint.url}</code><p class="mt-2 text-[10px] text-slate-400">Secret ••••{endpoint.secret_hint} · Last delivery {formatDate(endpoint.last_delivered_at)}</p></div><div class="flex flex-wrap gap-2"><button class="secondary px-3 py-2 text-xs" on:click={() => testWebhook(endpoint)}>Send test</button><button class="secondary px-3 py-2 text-xs" on:click={() => openEditWebhook(endpoint)}>Edit</button><button class="ghost px-3 py-2 text-xs" on:click={() => rotateSecret(endpoint)}>Rotate secret</button><button class="ghost px-3 py-2 text-xs text-rose-600" on:click={() => deleteWebhook(endpoint)}>Delete</button></div></div>
                            <div class="px-5 py-4"><div class="mb-4 flex flex-wrap gap-1.5">{#each endpoint.events as event}<span class="rounded-lg bg-indigo-50 px-2 py-1 text-[10px] font-semibold text-indigo-700">{event}</span>{/each}</div>{#if endpoint.deliveries.length}<div class="overflow-x-auto"><table class="w-full text-left text-xs"><thead class="text-[10px] uppercase text-slate-400"><tr><th class="pb-2">Event</th><th class="pb-2">Status</th><th class="pb-2">Attempts</th><th class="pb-2">HTTP</th><th class="pb-2 text-right">Created</th></tr></thead><tbody class="divide-y divide-slate-100">{#each endpoint.deliveries as delivery}<tr><td class="py-2.5 font-medium">{delivery.event}</td><td class="py-2.5"><span class="rounded-full px-2 py-0.5 text-[10px] font-bold {statusClass(delivery.status)}">{delivery.status}</span></td><td class="py-2.5">{delivery.attempt_count}</td><td class="py-2.5">{delivery.response_status ?? '—'}</td><td class="py-2.5 text-right text-slate-500">{formatDate(delivery.created_at)}</td></tr>{/each}</tbody></table></div>{:else}<p class="text-xs text-slate-400">No deliveries yet.</p>{/if}</div>
                        </article>
                    {/each}
                {:else}<div class="card py-16 text-center"><h3 class="text-sm font-bold">No webhook endpoints</h3><p class="mt-2 text-sm text-slate-500">{webhooksEnabled ? 'Create an endpoint and choose the events it should receive.' : 'Outbound webhooks are disabled in this environment.'}</p>{#if webhooksEnabled}<button class="mt-5" on:click={openCreateWebhook}>New endpoint</button>{/if}</div>{/if}
            </section>
        {/if}
    </main>
</AppShell>

{#if showPipeline}
    <div class="modal-backdrop" role="presentation"><form class="modal max-w-2xl" on:submit|preventDefault={savePipeline}><div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">Configure GitHub pipeline</h2><p class="mt-1 text-sm text-slate-500">Bind a workflow_dispatch workflow to an application.</p></div><button type="button" class="ghost p-2" on:click={() => showPipeline = false}>✕</button></div><div class="space-y-5 p-6">
        {#if discoveryError}<div class="rounded-xl bg-rose-50 px-4 py-3 text-xs font-medium text-rose-700">{discoveryError}</div>{/if}
        <label><span class="label">Repository</span><select bind:value={pipelineForm.repository_full_name} disabled={discovering} on:change={(event) => loadRepository(activeIntegration, event.currentTarget.value)}>{#each repositories as repository}<option value={repository.fullName}>{repository.fullName}</option>{/each}</select>{#if pipelineForm.errors.repository_full_name}<span class="error">{pipelineForm.errors.repository_full_name}</span>{/if}</label>
        <label><span class="label">Workflow</span><select value={pipelineForm.workflow_id} disabled={discovering} on:change={chooseWorkflow}>{#each workflows as workflow}<option value={workflow.id}>{workflow.name} · {workflow.path}</option>{/each}</select>{#if pipelineForm.errors.workflow_id}<span class="error">{pipelineForm.errors.workflow_id}</span>{/if}</label>
        <label><span class="label">Pipeline name</span><input bind:value={pipelineForm.name} required /></label>
        <div class="grid gap-4 sm:grid-cols-2"><label><span class="label">Application</span><select bind:value={pipelineForm.mobile_app_id} on:change={() => pipelineForm.app_identifier_id = ''}>{#each apps as app}<option value={app.id}>{app.name}</option>{/each}</select></label><label><span class="label">App identifier</span><select bind:value={pipelineForm.app_identifier_id}><option value="">All targets</option>{#each selectedApp?.identifiers ?? [] as identifier}<option value={identifier.id}>{identifier.name} · {identifier.platform}</option>{/each}</select></label></div>
        <div class="grid gap-4 sm:grid-cols-2"><label><span class="label">Build profile</span><select bind:value={pipelineForm.profile_type}>{#each profileTypes as profile}<option value={profile}>{profile}</option>{/each}</select></label><label><span class="label">Default branch or tag</span><select bind:value={pipelineForm.default_ref}>{#each refs as ref}<option value={ref.name}>{ref.name} · {ref.type}</option>{/each}</select></label></div>
        {#if selectedWorkflow && Object.keys(selectedWorkflow.inputs ?? {}).length}<fieldset><legend class="label">Workflow inputs</legend><div class="space-y-3 rounded-xl border border-slate-200 p-4">{#each Object.entries(selectedWorkflow.inputs) as [name, definition]}<label><span class="mb-1 block text-xs font-semibold">{name}{#if definition.required}<span class="text-rose-500"> *</span>{/if}</span><input value={pipelineForm.input_defaults[name] ?? ''} on:input={(event) => pipelineForm.input_defaults = { ...pipelineForm.input_defaults, [name]: event.currentTarget.value }} placeholder={definition.description ?? ''} /></label>{/each}</div></fieldset>{/if}
    </div><div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4"><button type="button" class="secondary" on:click={() => showPipeline = false}>Cancel</button><button disabled={pipelineForm.processing || discovering || !workflows.length}>{pipelineForm.processing ? 'Saving…' : 'Save pipeline'}</button></div></form></div>
{/if}

{#if showWebhook}
    <div class="modal-backdrop" role="presentation"><form class="modal max-w-2xl" on:submit|preventDefault={saveWebhook}><div class="flex items-start justify-between border-b border-slate-100 px-6 py-5"><div><h2 class="text-lg font-bold">{editingEndpoint ? 'Edit webhook endpoint' : 'New webhook endpoint'}</h2><p class="mt-1 text-sm text-slate-500">Choose a public HTTPS endpoint and subscribed events.</p></div><button type="button" class="ghost p-2" on:click={() => showWebhook = false}>✕</button></div><div class="space-y-5 p-6"><label><span class="label">Name</span><input bind:value={webhookForm.name} required />{#if webhookForm.errors.name}<span class="error">{webhookForm.errors.name}</span>{/if}</label><label><span class="label">Endpoint URL</span><input type="url" bind:value={webhookForm.url} placeholder="https://example.com/webhooks/app-manager" required />{#if webhookForm.errors.url}<span class="error">{webhookForm.errors.url}</span>{/if}</label><fieldset><legend class="label">Events</legend><div class="grid gap-2 rounded-xl border border-slate-200 p-4 sm:grid-cols-2">{#each webhookEventTypes as eventType}<label class="flex cursor-pointer items-center gap-2 text-xs"><input class="h-4 w-4" type="checkbox" checked={webhookForm.events.includes(eventType)} on:change={() => toggleEvent(eventType)} /><span>{eventType}</span></label>{/each}</div>{#if webhookForm.errors.events}<span class="error">{webhookForm.errors.events}</span>{/if}</fieldset>{#if editingEndpoint}<label class="flex items-center gap-2 text-sm"><input class="h-4 w-4" type="checkbox" bind:checked={webhookForm.enabled} /><span>Endpoint enabled</span></label>{/if}</div><div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4"><button type="button" class="secondary" on:click={() => showWebhook = false}>Cancel</button><button disabled={webhookForm.processing || !webhookForm.events.length}>{webhookForm.processing ? 'Saving…' : 'Save endpoint'}</button></div></form></div>
{/if}
