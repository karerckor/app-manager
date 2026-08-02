<script>
    import { onDestroy } from 'svelte';
    import { Link, router, useForm } from '@inertiajs/svelte';
    import {
        ArrowLeft,
        GitBranch,
        Link2,
        Plus,
        RefreshCw,
        Search,
        Unplug,
        X,
    } from '@lucide/svelte';
    import Select from '../../Components/Select.svelte';
    import WorkspacePage from '../../Components/WorkspacePage.svelte';
    import ConfirmDialog from '../../Components/ui/ConfirmDialog.svelte';
    import PipelineBuildModal from '../Apps/Modals/PipelineBuildModal.svelte';
    import PipelineBatchRenameModal from './Modals/PipelineBatchRenameModal.svelte';
    import PipelineBatchRunModal from './Modals/PipelineBatchRunModal.svelte';
    import PipelineFormModal from './Modals/PipelineFormModal.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let integration;
    export let pipelines = { data: [], links: [], total: 0 };
    export let pipelineFilters = {};
    export let platforms = [];
    export let apps = [];
    export let profileTypes = [];
    export let reportingModes = ['automatic', 'manual'];

    let showPipeline = false;
    let showRename = false;
    let renamingPipeline = null;
    let editingPipeline = null;
    let launchingPipeline = null;
    let selectedPipelineIds = {};
    let batchRunSelection = null;
    let batchRenameSelection = null;
    let pipelineSearch = pipelineFilters.search ?? '';
    let pipelineStatus = pipelineFilters.status ?? '';
    let pipelinePlatform = pipelineFilters.platform ?? '';
    let pipelineProfile = pipelineFilters.profile ?? '';
    let pipelineSearchTimer;
    let loadingApps = false;
    let showDisconnectConfirmation = false;
    let disconnecting = false;

    const renameForm = useForm({ name: '' });

    const showPipelineForm = (pipeline = null) => {
        editingPipeline = pipeline;
        showPipeline = true;
    };
    const openPipeline = (pipeline = null) => {
        if (loadingApps) return;
        if (apps.length) {
            showPipelineForm(pipeline);
            return;
        }

        loadingApps = true;
        router.reload({
            only: ['apps'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => showPipelineForm(pipeline),
            onFinish: () => loadingApps = false,
        });
    };
    const closePipeline = () => {
        showPipeline = false;
        editingPipeline = null;
    };
    const openPipelineBuild = (pipeline) => launchingPipeline = pipeline;
    const closePipelineBuild = () => launchingPipeline = null;
    const syncIntegration = () => router.post(integration.sync_url, {}, { preserveScroll: true });
    const requestDisconnect = () => showDisconnectConfirmation = true;
    const closeDisconnectConfirmation = () => {
        if (!disconnecting) showDisconnectConfirmation = false;
    };
    const disconnectIntegration = () => {
        if (disconnecting) return;

        router.delete(integration.disconnect_url, {
            preserveScroll: true,
            onStart: () => disconnecting = true,
            onFinish: () => disconnecting = false,
        });
    };
    const openRename = (pipeline) => {
        renamingPipeline = pipeline;
        renameForm.name = pipeline.name;
        renameForm.clearErrors();
        showRename = true;
    };
    const saveRename = () => renameForm.patch(renamingPipeline.rename_url, {
        preserveScroll: true,
        onSuccess: () => showRename = false,
    });
    const deletePipeline = (pipeline) => {
        if (confirm(`Remove pipeline “${pipeline.name}”?`)) {
            router.delete(pipeline.destroy_url, { preserveScroll: true });
        }
    };
    const selectedIdsForGroup = (group, selections = selectedPipelineIds) => selections[group.key] ?? [];
    const selectedPipelinesForGroup = (group) => {
        const ids = selectedIdsForGroup(group);

        return group.pipelines.filter((pipeline) => ids.includes(pipeline.id));
    };
    const togglePipelineSelection = (group, pipelineId) => {
        const ids = selectedIdsForGroup(group);

        selectedPipelineIds = {
            ...selectedPipelineIds,
            [group.key]: ids.includes(pipelineId)
                ? ids.filter((id) => id !== pipelineId)
                : [...ids, pipelineId],
        };
    };
    const toggleGroupSelection = (group) => {
        const ids = selectedIdsForGroup(group);

        selectedPipelineIds = {
            ...selectedPipelineIds,
            [group.key]: ids.length === group.pipelines.length
                ? []
                : group.pipelines.map((pipeline) => pipeline.id),
        };
    };
    const clearGroupSelection = (groupKey) => {
        selectedPipelineIds = { ...selectedPipelineIds, [groupKey]: [] };
    };
    const openBatchRun = (group) => {
        const pipelines = selectedPipelinesForGroup(group);

        if (pipelines.length) batchRunSelection = { integration, groupKey: group.key, pipelines };
    };
    const closeBatchRun = () => batchRunSelection = null;
    const completeBatchRun = () => {
        if (batchRunSelection) clearGroupSelection(batchRunSelection.groupKey);
    };
    const openBatchRename = (group) => {
        const pipelines = selectedPipelinesForGroup(group);

        if (pipelines.length) batchRenameSelection = { integration, groupKey: group.key, pipelines };
    };
    const closeBatchRename = () => batchRenameSelection = null;
    const completeBatchRename = () => {
        if (batchRenameSelection) clearGroupSelection(batchRenameSelection.groupKey);
    };
    const deleteSelectedPipelines = (group) => {
        const pipelines = selectedPipelinesForGroup(group);
        const count = pipelines.length;

        if (!count || !confirm(`Remove ${count} selected pipeline binding${count === 1 ? '' : 's'}? Build history will be preserved.`)) return;

        router.delete(integration.pipelines_batch_destroy_url, {
            data: { pipeline_ids: pipelines.map((pipeline) => pipeline.id) },
            preserveScroll: true,
            onSuccess: () => clearGroupSelection(group.key),
        });
    };
    const groupPipelines = (pipelines) => {
        const groups = new Map();

        for (const pipeline of pipelines) {
            const key = JSON.stringify({
                enabled: pipeline.enabled,
                profile_type: pipeline.profile_type,
                reporting_mode: pipeline.reporting_mode,
                build_id_input: pipeline.build_id_input,
                repository_full_name: pipeline.repository_full_name,
                workflow_id: pipeline.workflow_id,
                default_ref: pipeline.default_ref,
                input_schema: pipeline.input_schema,
                input_defaults: pipeline.input_defaults,
            });
            const group = groups.get(key);

            if (group) group.pipelines.push(pipeline);
            else groups.set(key, { key, pipeline, pipelines: [pipeline] });
        }

        return [...groups.values()].map((group) => ({
            ...group,
            key: group.pipelines.map((pipeline) => pipeline.id).sort().join(':'),
            names: [...new Set(group.pipelines.map((pipeline) => pipeline.name))],
        }));
    };
    const statusClass = (status) => ({
        active: 'bg-ok/10 text-ok',
        degraded: 'bg-warn-text/10 text-warn-fill',
        disconnected: 'bg-border-subtle text-muted',
    }[status] ?? 'bg-border-subtle text-muted');
    const formatDate = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : 'Never';
    const visitPipelineFilters = () => {
        selectedPipelineIds = {};
        router.get(integration.detail_url, {
            search: pipelineSearch.trim() || undefined,
            status: pipelineStatus || undefined,
            platform: pipelinePlatform || undefined,
            profile: pipelineProfile || undefined,
        }, {
            only: ['pipelines', 'pipelineFilters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const schedulePipelineSearch = () => {
        clearTimeout(pipelineSearchTimer);
        pipelineSearchTimer = setTimeout(visitPipelineFilters, 350);
    };
    const clearPipelineFilters = () => {
        clearTimeout(pipelineSearchTimer);
        pipelineSearch = '';
        pipelineStatus = '';
        pipelinePlatform = '';
        pipelineProfile = '';
        visitPipelineFilters();
    };

    $: pipelineItems = pipelines?.data ?? [];
    $: pipelineGroups = groupPipelines(pipelineItems);
    $: pageLinks = (pipelines?.links ?? []).slice(1, -1);
    $: paginationKey = (pipelines?.links ?? []).map((link) => link.url ?? link.label).join('|');
    $: hasPipelineFilters = Boolean(pipelineSearch || pipelineStatus || pipelinePlatform || pipelineProfile);
    $: linkedAppsCount = integration.linked_apps_count;
    $: providerMark = integration.provider === 'github' ? 'GH' : integration.provider === 'gitlab' ? 'GL' : 'EAS';
    $: providerColor = integration.provider === 'gitlab' ? 'bg-warn-fill' : integration.provider === 'expo' ? 'bg-brand' : 'bg-text';

    onDestroy(() => clearTimeout(pipelineSearchTimer));
</script>

<svelte:head><title>{integration.name} · CI/CD Integrations · App Manager</title></svelte:head>

<WorkspacePage
    {auth}
    {navigationTeams}
    {navigationTeam}
    active="integrations"
    eyebrow="CI/CD integration"
    title={integration.name}
    description="Manage linked applications, pipeline configuration, and build runs for this provider connection."
    {flash}
>
    {#snippet breadcrumbs()}
        <Link href={integration.index_url} class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted transition hover:text-brand">
            <ArrowLeft class="h-3.5 w-3.5" strokeWidth={2.2} />
            CI/CD integrations
        </Link>
    {/snippet}

    {#snippet actions()}
        <button class="secondary inline-flex items-center gap-1.5 px-3 py-2 text-xs" disabled={integration.status === 'disconnected'} on:click={syncIntegration}>
            <RefreshCw class="h-3.5 w-3.5" strokeWidth={2} />
            Sync
        </button>
        <button class="ghost inline-flex items-center gap-1.5 px-3 py-2 text-xs text-danger" disabled={integration.status === 'disconnected'} on:click={requestDisconnect}>
            <Unplug class="h-3.5 w-3.5" strokeWidth={2} />
            Disconnect
        </button>
    {/snippet}

    <section class="relative mb-5 overflow-hidden rounded-md border border-border bg-surface-raised">
        <div class="absolute inset-y-0 left-0 w-1 {integration.provider === 'gitlab' ? 'bg-warn-fill' : 'bg-brand'}"></div>
        <div class="flex flex-wrap items-start justify-between gap-5 px-6 py-5">
            <div class="flex min-w-0 items-start gap-4">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md font-mono text-xs font-black text-white {providerColor}">{providerMark}</span>
                <div class="min-w-0">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <h2 class="truncate text-sm font-bold text-text">{integration.provider_name}</h2>
                        <span class="rounded-full px-2 py-0.5 text-2xs font-bold uppercase {statusClass(integration.status)}">{integration.status}</span>
                        {#if integration.connection_type === 'self_hosted'}<span class="rounded-full bg-warn-text/10 px-2 py-0.5 text-2xs font-bold uppercase text-warn-fill">self-hosted</span>{/if}
                    </div>
                    <p class="mt-1 truncate font-mono text-2xs text-faint">
                        {integration.account_name || integration.name}{#if integration.instance_url} · {integration.instance_url}{/if}
                    </p>
                </div>
            </div>

            <dl class="grid min-w-full grid-cols-2 gap-px overflow-hidden rounded-md border border-border bg-border sm:min-w-0 sm:grid-cols-3">
                <div class="bg-surface-sunken px-4 py-3">
                    <dt class="font-condensed text-2xs font-semibold tracking-[0.12em] text-faint uppercase">Linked apps</dt>
                    <dd class="mt-1 font-mono text-sm font-semibold text-text">{linkedAppsCount}</dd>
                </div>
                <div class="bg-surface-sunken px-4 py-3">
                    <dt class="font-condensed text-2xs font-semibold tracking-[0.12em] text-faint uppercase">Bindings</dt>
                    <dd class="mt-1 font-mono text-sm font-semibold text-text">{integration.pipelines_count}</dd>
                </div>
                <div class="col-span-2 bg-surface-sunken px-4 py-3 sm:col-span-1">
                    <dt class="font-condensed text-2xs font-semibold tracking-[0.12em] text-faint uppercase">Last synced</dt>
                    <dd class="mt-1 whitespace-nowrap text-2xs font-semibold text-text">{formatDate(integration.last_synced_at)}</dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="card overflow-clip p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="grid h-8 w-8 place-items-center rounded-md bg-brand-soft text-brand"><Link2 class="h-4 w-4" strokeWidth={1.9} /></span>
                    <div>
                        <h2 class="text-sm font-bold text-text">Linked applications</h2>
                        <p class="mt-0.5 text-xs text-muted">Each binding connects an application to a provider workflow.</p>
                    </div>
                </div>
            </div>
            <button
                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs"
                disabled={integration.status !== 'active' || !integration.has_eligible_apps || loadingApps}
                on:click={() => openPipeline()}
            >
                <Plus class="h-3.5 w-3.5" strokeWidth={2} />
                {loadingApps ? 'Loading apps…' : 'Configure pipeline'}
            </button>
        </div>

        <form class="border-b border-border-subtle bg-surface-sunken/40 px-5 py-3.5" on:submit|preventDefault={visitPipelineFilters}>
            <div class="grid gap-2.5 md:grid-cols-2 xl:grid-cols-[minmax(260px,1fr)_150px_150px_170px_auto] xl:items-end">
                <label class="relative block md:col-span-2 xl:col-span-1">
                    <span class="label">Search bindings</span>
                    <Search class="pointer-events-none absolute bottom-2.5 left-3 h-4 w-4 text-faint" strokeWidth={1.8} />
                    <input
                        class="pl-9"
                        type="search"
                        bind:value={pipelineSearch}
                        placeholder="App, pipeline, repository…"
                        on:input={schedulePipelineSearch}
                    />
                </label>
                <label class="block">
                    <span class="label">Platform</span>
                    <Select
                        bind:value={pipelinePlatform}
                        options={[{ value: '', label: 'All platforms' }, ...platforms]}
                        ariaLabel="Platform"
                        on:change={visitPipelineFilters}
                    />
                </label>
                <label class="block">
                    <span class="label">Status</span>
                    <Select
                        bind:value={pipelineStatus}
                        options={[{ value: '', label: 'All statuses' }, { value: 'enabled', label: 'Enabled' }, { value: 'disabled', label: 'Disabled' }]}
                        ariaLabel="Status"
                        on:change={visitPipelineFilters}
                    />
                </label>
                <label class="block">
                    <span class="label">Build profile</span>
                    <Select
                        bind:value={pipelineProfile}
                        options={[{ value: '', label: 'All profiles' }, ...profileTypes.map((profile) => ({ value: profile, label: profile }))]}
                        ariaLabel="Build profile"
                        on:change={visitPipelineFilters}
                    />
                </label>
                <button
                    type="button"
                    class="secondary inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs"
                    class:invisible={!hasPipelineFilters}
                    tabindex={hasPipelineFilters ? 0 : -1}
                    on:click={clearPipelineFilters}
                >
                    <X class="h-3.5 w-3.5" strokeWidth={2} />
                    Clear
                </button>
            </div>
            <p class="mt-2.5 font-mono text-2xs text-faint tabular-nums">
                {#if pipelines.total}
                    Showing {pipelines.from}–{pipelines.to} of {pipelines.total} binding{pipelines.total === 1 ? '' : 's'}{hasPipelineFilters ? ' matching these filters' : ''}
                {:else if hasPipelineFilters}
                    No bindings match these filters
                {:else}
                    No bindings configured
                {/if}
            </p>
        </form>

        {#if pipelineGroups.length}
            <div class="divide-y divide-border-subtle">
                {#each pipelineGroups as group}
                    {#if group.pipelines.length === 1}
                        <div class="grid gap-4 px-5 py-4 transition hover:bg-surface-sunken md:grid-cols-[minmax(0,1fr)_190px_auto] md:items-center">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-md bg-brand-soft text-brand ring-1 ring-inset ring-brand-soft-line"><GitBranch class="h-4 w-4" strokeWidth={1.9} /></span>
                                <div class="min-w-0">
                                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        <p class="truncate text-xs font-semibold text-text">{group.pipeline.name}</p>
                                        <span class="rounded bg-brand-soft px-1.5 py-0.5 text-2xs font-bold text-brand uppercase">{group.pipeline.reporting_mode}</span>
                                        {#if !group.pipeline.enabled}<span class="rounded bg-border-subtle px-1.5 py-0.5 text-2xs font-bold text-muted">Disabled</span>{/if}
                                    </div>
                                    <p class="mt-1 truncate font-mono text-2xs text-faint">{group.pipeline.repository_full_name} · {group.pipeline.workflow_name} · {group.pipeline.default_ref}</p>
                                </div>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold text-text">{group.pipeline.app_name}</p>
                                <p class="mt-1 truncate text-2xs text-faint">{group.pipeline.target_name || 'All targets'} · {group.pipeline.profile_type}</p>
                            </div>
                            <div class="flex flex-wrap justify-start gap-1.5 md:justify-end">
                                <button class="px-3 py-2 text-xs" disabled={!group.pipeline.enabled || integration.status !== 'active'} on:click={() => openPipelineBuild(group.pipeline)}>Run build</button>
                                <button class="secondary px-2.5 py-2 text-xs" on:click={() => openPipeline(group.pipeline)}>Edit</button>
                                <button class="secondary px-2.5 py-2 text-xs" on:click={() => openRename(group.pipeline)}>Rename</button>
                                <button class="ghost px-2 py-2 text-xs text-danger" on:click={() => deletePipeline(group.pipeline)}>Remove</button>
                            </div>
                        </div>
                    {:else}
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <p class="min-w-0 truncate text-xs font-semibold text-text" title={group.names.join(', ')}>{group.names.join(', ')}</p>
                                        <span class="shrink-0 rounded bg-brand-soft px-1.5 py-0.5 text-2xs font-bold text-brand uppercase">{group.pipeline.reporting_mode}</span>
                                        {#if !group.pipeline.enabled}<span class="shrink-0 rounded bg-border-subtle px-1.5 py-0.5 text-2xs font-bold text-muted">Disabled</span>{/if}
                                    </div>
                                    <p class="mt-1 truncate font-mono text-2xs text-faint">{group.pipeline.repository_full_name} · {group.pipeline.workflow_name} · {group.pipeline.default_ref}</p>
                                </div>
                                <span class="rounded-full bg-border-subtle px-2.5 py-1 text-2xs font-bold text-muted">{group.pipelines.length} on this page</span>
                            </div>

                            <div class="mt-3 divide-y divide-border-subtle overflow-hidden rounded-md border border-border bg-surface-raised">
                                <div class="flex flex-wrap items-center justify-between gap-3 bg-surface-sunken px-4 py-2.5">
                                    <label class="flex cursor-pointer items-center gap-2 text-xs font-semibold text-text">
                                        <input
                                            class="h-4 w-4 shrink-0 accent-brand shadow-none"
                                            type="checkbox"
                                            checked={selectedIdsForGroup(group, selectedPipelineIds).length === group.pipelines.length}
                                            aria-label={`Select all ${group.pipelines.length} applications`}
                                            on:change={() => toggleGroupSelection(group)}
                                        />
                                        <span>{selectedIdsForGroup(group, selectedPipelineIds).length ? `${selectedIdsForGroup(group, selectedPipelineIds).length} selected` : 'Select all'}</span>
                                    </label>
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <button class="px-3 py-2 text-xs" disabled={!selectedIdsForGroup(group, selectedPipelineIds).length || !group.pipeline.enabled || integration.status !== 'active'} on:click={() => openBatchRun(group)}>Run selected</button>
                                        <button class="secondary px-3 py-2 text-xs" disabled={!selectedIdsForGroup(group, selectedPipelineIds).length} on:click={() => openBatchRename(group)}>Rename selected</button>
                                        <button class="ghost px-3 py-2 text-xs text-danger" disabled={!selectedIdsForGroup(group, selectedPipelineIds).length} on:click={() => deleteSelectedPipelines(group)}>Remove selected</button>
                                    </div>
                                </div>
                                {#each group.pipelines as pipeline}
                                    <div class="grid gap-3 px-4 py-3 transition hover:bg-surface-sunken md:grid-cols-[minmax(180px,1fr)_auto] md:items-center">
                                        <label class="flex min-w-0 cursor-pointer items-center gap-3 text-xs">
                                            <input
                                                class="h-4 w-4 shrink-0 accent-brand shadow-none"
                                                type="checkbox"
                                                checked={selectedIdsForGroup(group, selectedPipelineIds).includes(pipeline.id)}
                                                aria-label={`Select ${pipeline.app_name}`}
                                                on:change={() => togglePipelineSelection(group, pipeline.id)}
                                            />
                                            <span class="min-w-0"><span class="block truncate font-semibold text-text">{pipeline.app_name}</span><span class="mt-1 block truncate text-muted">{pipeline.target_name || 'All targets'} · {pipeline.profile_type}</span></span>
                                        </label>
                                        <div class="flex flex-wrap justify-start gap-2 md:justify-end">
                                            <button class="px-3 py-2 text-xs" disabled={!pipeline.enabled || integration.status !== 'active'} on:click={() => openPipelineBuild(pipeline)}>Run build</button>
                                            <button class="secondary px-2 py-2 text-xs" on:click={() => openPipeline(pipeline)}>Edit</button>
                                            <button class="secondary px-2 py-2 text-xs" on:click={() => openRename(pipeline)}>Rename</button>
                                            <button class="ghost px-2 py-2 text-xs text-danger" on:click={() => deletePipeline(pipeline)}>Remove</button>
                                        </div>
                                    </div>
                                {/each}
                            </div>
                        </div>
                    {/if}
                {/each}
            </div>

            {#if pipelines.last_page > 1}
                {#key paginationKey}
                    <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle px-5 py-4" aria-label="Pipeline pagination">
                        <p class="font-mono text-2xs text-faint tabular-nums">{pipelines.from}–{pipelines.to} of {pipelines.total}</p>
                        <div class="flex items-center gap-1.5">
                            {#if pipelines.prev_page_url}<Link href={pipelines.prev_page_url} class="secondary px-3 py-2 text-xs" only={['pipelines', 'pipelineFilters']} preserveState preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}
                            {#each pageLinks as link}
                                {#if link.url}<Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center font-mono text-xs tabular-nums {link.active ? 'border-brand-soft-line bg-brand-soft text-brand' : ''}" only={['pipelines', 'pipelineFilters']} preserveState preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>{:else}<span class="px-1 text-xs text-faint">{link.label}</span>{/if}
                            {/each}
                            {#if pipelines.next_page_url}<Link href={pipelines.next_page_url} class="secondary px-3 py-2 text-xs" only={['pipelines', 'pipelineFilters']} preserveState preserveScroll>Next</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>{/if}
                        </div>
                    </nav>
                {/key}
            {/if}
        {:else}
            <div class="px-5 py-14 text-center">
                <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-brand-soft text-brand"><GitBranch class="h-5 w-5" strokeWidth={1.8} /></span>
                <h3 class="mt-3 text-sm font-bold text-text">{hasPipelineFilters ? 'No matching bindings' : 'No applications linked yet'}</h3>
                <p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-muted">{hasPipelineFilters ? 'Try a different search or clear the filters.' : 'Configure a provider workflow to link an application and enable build runs.'}</p>
                {#if hasPipelineFilters}
                    <button type="button" class="secondary mt-4 inline-flex items-center gap-1.5 px-3 py-2 text-xs" on:click={clearPipelineFilters}><X class="h-3.5 w-3.5" />Clear filters</button>
                {:else}
                    <button class="mt-4 inline-flex items-center gap-1.5 px-3 py-2 text-xs" disabled={integration.status !== 'active' || !integration.has_eligible_apps || loadingApps} on:click={() => openPipeline()}><Plus class="h-3.5 w-3.5" />{loadingApps ? 'Loading apps…' : 'Configure pipeline'}</button>
                {/if}
            </div>
        {/if}
    </section>
</WorkspacePage>

<ConfirmDialog
    open={showDisconnectConfirmation}
    title={`Disconnect ${integration.name}?`}
    description="Existing build history will be preserved."
    confirmLabel="Disconnect"
    busyLabel="Disconnecting…"
    busy={disconnecting}
    onConfirm={disconnectIntegration}
    onCancel={closeDisconnectConfirmation}
/>

<PipelineBuildModal pipeline={launchingPipeline} onClose={closePipelineBuild} />
<PipelineBatchRunModal
    selection={batchRunSelection}
    url={batchRunSelection?.integration.pipelines_batch_run_url ?? ''}
    onClose={closeBatchRun}
    onComplete={completeBatchRun}
/>
<PipelineBatchRenameModal
    selection={batchRenameSelection}
    url={batchRenameSelection?.integration.pipelines_batch_rename_url ?? ''}
    onClose={closeBatchRename}
    onComplete={completeBatchRename}
/>

<PipelineFormModal
    open={showPipeline}
    {integration}
    pipeline={editingPipeline}
    {apps}
    {profileTypes}
    {reportingModes}
    onClose={closePipeline}
/>

{#if showRename}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && (showRename = false)}>
        <form class="modal max-w-md" on:submit|preventDefault={saveRename}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5">
                <div><h2 class="text-lg font-bold">Rename pipeline</h2><p class="mt-1 text-sm text-muted">Change the display name only. Repository, workflow, and other settings stay the same.</p></div>
                <button type="button" class="ghost p-2" on:click={() => showRename = false}>✕</button>
            </div>
            <div class="p-6"><label><span class="label">Pipeline name</span><input bind:value={renameForm.name} required />{#if renameForm.errors.name}<span class="error">{renameForm.errors.name}</span>{/if}</label></div>
            <div class="flex justify-end gap-3 border-t border-border-subtle bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={() => showRename = false}>Cancel</button><button disabled={renameForm.processing}>{renameForm.processing ? 'Saving…' : 'Save name'}</button></div>
        </form>
    </div>
{/if}
