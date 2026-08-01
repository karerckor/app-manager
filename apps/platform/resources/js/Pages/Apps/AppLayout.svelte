<script>
    import { Link } from '@inertiajs/svelte';
    import { Pencil, ChevronDown, Play, CirclePlus, GitBranch } from '@lucide/svelte';
    import AppShell from '../../Components/AppShell.svelte';
    import TabSelector from '../../Components/TabSelector.svelte';
    import TagEditor from '../../Components/TagEditor.svelte';
    import EditAppModal from './Modals/EditAppModal.svelte';
    import ManualBuildModal from './Modals/ManualBuildModal.svelte';
    import PipelineBuildModal from './Modals/PipelineBuildModal.svelte';
    import AppPipelinesModal from './Modals/AppPipelinesModal.svelte';
    import PipelineFormModal from '../Integrations/Modals/PipelineFormModal.svelte';
    import { initials } from './lib.js';

    export let auth;
    export let flash = {};
    export let app;
    export let pipelines = [];
    export let identifiers = [];
    export let buildProfiles = [];
    export let tagSuggestions = [];
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let active = 'overview';

    let showEdit = false;
    let showManual = false;
    let launchingPipeline = null;
    let showPipelines = false;
    let configuringIntegration = null;
    let editingPipeline = null;
    let showRunMenu = false;
    let runMenuEl;

    const openEdit = () => showEdit = true;
    const closeEdit = () => showEdit = false;

    const toggleRunMenu = () => showRunMenu = !showRunMenu;
    const closeRunMenu = () => showRunMenu = false;

    const openManualBuild = () => {
        closeRunMenu();
        showManual = true;
    };
    const closeManualBuild = () => showManual = false;

    const openPipelineBuild = (pipeline) => {
        closeRunMenu();
        launchingPipeline = pipeline;
    };
    const closePipelineBuild = () => launchingPipeline = null;

    const openPipelines = () => showPipelines = true;
    const closePipelines = () => showPipelines = false;
    const configurePipeline = (integration, pipeline = null) => {
        configuringIntegration = integration;
        editingPipeline = pipeline;
    };
    const closePipelineForm = () => {
        configuringIntegration = null;
        editingPipeline = null;
    };
    const runManagedPipeline = (pipeline) => {
        closePipelines();
        openPipelineBuild(pipeline);
    };

    const onWindowPointer = (event) => {
        if (showRunMenu && runMenuEl && !runMenuEl.contains(event.target)) closeRunMenu();
    };
    const onWindowKeydown = (event) => {
        if (showRunMenu && event.key === 'Escape') closeRunMenu();
    };

    $: sectionTabs = [
        { value: 'overview', label: 'Overview', href: app.urls.overview, active: active === 'overview' },
        { value: 'media', label: 'Files and Media Library', href: app.urls.media_page, active: active === 'media' },
        { value: 'identifiers', label: 'Platforms and App Identifiers', href: app.urls.identifiers_page, active: active === 'identifiers', count: identifiers.length },
        { value: 'builds', label: 'Builds', href: app.urls.builds_page, active: active === 'builds' },
        ...(app.urls.expo_page ? [{ value: 'expo', label: 'Expo EAS', href: app.urls.expo_page, active: active === 'expo', tone: 'violet' }] : []),
    ];
    $: pipelineConfiguration = app.pipeline_configuration;
    $: pipelineApp = { ...app, identifiers };
</script>

<svelte:window on:pointerdown={onWindowPointer} on:keydown={onWindowKeydown} />

<svelte:head><title>{app.name} · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="apps">
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
        <nav class="mb-4 flex items-center gap-2 text-xs font-medium text-faint"><Link href={app.urls.index} class="hover:text-brand">Applications</Link><span class="text-border-control">/</span><span class="text-muted">{app.name}</span></nav>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                {#if app.assets.app_icon}<img src={app.assets.app_icon.preview_url} alt={`${app.name} icon`} class="h-10 w-10 shrink-0 rounded-md bg-surface-raised object-cover ring-1 ring-border" />{:else}<span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-gradient-to-br from-brand to-brand text-xs font-bold text-white">{initials(app.name)}</span>{/if}
                <div class="min-w-0">
                    <div class="flex items-center gap-2"><h1 class="truncate font-condensed text-3xl font-semibold text-text uppercase">{app.name}</h1><span class="rounded-full bg-ok/10 px-2 py-0.5 text-2xs font-bold text-ok uppercase">Active</span></div>
                    <p class="mt-0.5 truncate text-xs text-muted">{app.description || app.slug}</p>
                    <div class="mt-2"><TagEditor tags={app.tags} url={app.urls.tags} suggestions={tagSuggestions} /></div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="secondary flex items-center gap-2 px-3 py-2 text-xs" on:click={openEdit}>
                    <Pencil class="h-3.5 w-3.5" strokeWidth={2.2} />
                    Edit
                </button>
                {#if auth?.team?.permissions?.manage_integrations && pipelineConfiguration}
                    <button type="button" class="secondary flex items-center gap-2 px-3 py-2 text-xs" on:click={openPipelines}>
                        <GitBranch class="h-3.5 w-3.5" strokeWidth={2.2} />
                        Pipelines
                    </button>
                {/if}
                {#if auth?.team?.permissions?.run_pipelines}
                    {#if pipelines.length}
                        <div class="relative" bind:this={runMenuEl}>
                            <button type="button" class="flex items-center gap-2 px-3 py-2 text-xs" aria-haspopup="true" aria-expanded={showRunMenu} on:click={toggleRunMenu}>
                                Run build
                                <ChevronDown class="h-3.5 w-3.5" strokeWidth={2.2} />
                            </button>
                            {#if showRunMenu}
                                <div class="absolute right-0 top-full z-20 mt-2 w-48 rounded-md border border-border bg-surface-raised p-1 shadow-2xl">
                                    {#each pipelines as pipeline}
                                        <button type="button" class="flex w-full justify-start gap-2 rounded-sm bg-transparent px-2.5 py-2 text-left text-xs font-semibold text-text shadow-none hover:bg-surface-sunken" on:click={() => openPipelineBuild(pipeline)}>
                                            <Play class="h-3.5 w-3.5 shrink-0 text-brand" fill="currentColor" />
                                            <span class="min-w-0 truncate">{pipeline.name}</span>
                                        </button>
                                    {/each}
                                    <div class="mx-2 my-1 h-px bg-border-subtle"></div>
                                    <button type="button" class="flex w-full justify-start gap-2 rounded-sm bg-transparent px-2.5 py-2 text-left text-xs font-semibold text-text shadow-none hover:bg-surface-sunken" on:click={openManualBuild}>
                                        <CirclePlus class="h-3.5 w-3.5 shrink-0 text-muted" strokeWidth={1.9} />
                                        Manual build
                                    </button>
                                </div>
                            {/if}
                        </div>
                    {:else}
                        <button type="button" class="flex items-center gap-2 px-3 py-2 text-xs" on:click={openManualBuild}>
                            <CirclePlus class="h-3.5 w-3.5" strokeWidth={2.2} />
                            Run build
                        </button>
                    {/if}
                {/if}
            </div>
        </header>

        {#if flash?.status}<div class="mb-6 flex items-center gap-3 rounded-md border border-ok/20 bg-ok/10 px-4 py-3 text-sm font-medium text-ok"><span class="grid h-5 w-5 place-items-center rounded-full bg-ok text-xs text-white">✓</span>{flash.status}</div>{/if}

        <div class="mb-5">
            <TabSelector variant="pills" ariaLabel="Application sections" items={sectionTabs} />
        </div>

        <slot />
    </main>
</AppShell>

<EditAppModal {app} open={showEdit} onClose={closeEdit} />
<ManualBuildModal {app} {identifiers} {buildProfiles} open={showManual} onClose={closeManualBuild} />
<PipelineBuildModal pipeline={launchingPipeline} onClose={closePipelineBuild} />
<AppPipelinesModal
    open={showPipelines}
    {app}
    configuration={pipelineConfiguration}
    onClose={closePipelines}
    onConfigure={configurePipeline}
    onRun={runManagedPipeline}
/>
<PipelineFormModal
    open={configuringIntegration !== null}
    integration={configuringIntegration}
    pipeline={editingPipeline}
    apps={[pipelineApp]}
    profileTypes={pipelineConfiguration?.profile_types ?? []}
    reportingModes={pipelineConfiguration?.reporting_modes ?? ['automatic', 'manual']}
    fixedApp={pipelineApp}
    onClose={closePipelineForm}
/>
