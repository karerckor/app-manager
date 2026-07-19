<script>
    import { Link } from '@inertiajs/svelte';
    import { Pencil, ChevronDown, Play, CirclePlus } from '@lucide/svelte';
    import AppShell from '../../Components/AppShell.svelte';
    import TagEditor from '../../Components/TagEditor.svelte';
    import EditAppModal from './Modals/EditAppModal.svelte';
    import ManualBuildModal from './Modals/ManualBuildModal.svelte';
    import PipelineBuildModal from './Modals/PipelineBuildModal.svelte';
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

    const onWindowPointer = (event) => {
        if (showRunMenu && runMenuEl && !runMenuEl.contains(event.target)) closeRunMenu();
    };
    const onWindowKeydown = (event) => {
        if (showRunMenu && event.key === 'Escape') closeRunMenu();
    };
</script>

<svelte:window on:pointerdown={onWindowPointer} on:keydown={onWindowKeydown} />

<svelte:head><title>{app.name} · App Manager</title></svelte:head>

<AppShell {auth} teams={navigationTeams} currentTeam={navigationTeam} active="apps">
    <main class="mx-auto max-w-[1240px] px-5 py-8 sm:px-8 lg:px-10 lg:py-9">
        <nav class="mb-6 flex items-center gap-2 text-xs font-medium text-slate-500"><Link href={app.urls.index} class="hover:text-indigo-600">Applications</Link><span class="text-slate-300">/</span><span class="text-slate-800">{app.name}</span></nav>

        <header class="mb-7 flex flex-wrap items-start justify-between gap-5">
            <div class="flex min-w-0 items-start gap-4">
                {#if app.assets.app_icon}<img src={app.assets.app_icon.preview_url} alt={`${app.name} icon`} class="h-14 w-14 shrink-0 rounded-2xl bg-white object-cover shadow-lg shadow-slate-200 ring-1 ring-slate-200" />{:else}<span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-sm font-bold text-white shadow-lg shadow-indigo-200">{initials(app.name)}</span>{/if}
                <div class="min-w-0">
                    <div class="flex items-center gap-2"><h1 class="truncate text-2xl font-bold tracking-tight">{app.name}</h1><span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 uppercase">Active</span></div>
                    <p class="mt-1 text-sm text-slate-500">{app.description || app.slug}</p>
                    <div class="mt-2.5"><TagEditor tags={app.tags} url={app.urls.tags} suggestions={tagSuggestions} /></div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="secondary flex items-center gap-2" on:click={openEdit}>
                    <Pencil class="h-4 w-4" strokeWidth={2.2} />
                    Edit
                </button>
                {#if auth?.team?.permissions?.run_pipelines}
                    <div class="relative" bind:this={runMenuEl}>
                        <button type="button" class="flex items-center gap-2" aria-haspopup="true" aria-expanded={showRunMenu} on:click={toggleRunMenu}>
                            Run build
                            <ChevronDown class="h-4 w-4" strokeWidth={2.2} />
                        </button>
                        {#if showRunMenu}
                            <div class="absolute right-0 top-full z-20 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-950/10">
                                {#each pipelines as pipeline}
                                    <button type="button" class="flex w-full items-center gap-2.5 rounded-lg bg-transparent px-3 py-2 text-left text-xs font-semibold text-slate-700 shadow-none hover:bg-slate-50" on:click={() => openPipelineBuild(pipeline)}>
                                        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-indigo-50 text-indigo-600"><Play class="h-3 w-3" fill="currentColor" /></span>
                                        <span class="min-w-0 truncate">{pipeline.name}</span>
                                    </button>
                                {/each}
                                {#if pipelines.length}<div class="my-1 h-px bg-slate-100"></div>{/if}
                                <button type="button" class="flex w-full items-center gap-2.5 rounded-lg bg-transparent px-3 py-2 text-left text-xs font-semibold text-slate-700 shadow-none hover:bg-slate-50" on:click={openManualBuild}>
                                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-500"><CirclePlus class="h-3 w-3" strokeWidth={1.9} /></span>
                                    Manual build
                                </button>
                            </div>
                        {/if}
                    </div>
                {/if}
            </div>
        </header>

        {#if flash?.status}<div class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>{flash.status}</div>{/if}

        <div class="mb-7 flex gap-1 border-b border-slate-200">
            <Link href={app.urls.overview} class="rounded-none border-b-2 px-4 py-3 shadow-none {active === 'overview' ? 'border-indigo-600 bg-transparent text-indigo-700' : 'border-transparent bg-transparent text-slate-500 hover:bg-transparent hover:text-slate-900'}">Overview</Link>
            <Link href={app.urls.identifiers_page} class="rounded-none border-b-2 px-4 py-3 shadow-none {active === 'identifiers' ? 'border-indigo-600 bg-transparent text-indigo-700' : 'border-transparent bg-transparent text-slate-500 hover:bg-transparent hover:text-slate-900'}">Identifiers</Link>
            <Link href={app.urls.builds_page} class="rounded-none border-b-2 px-4 py-3 shadow-none {active === 'builds' ? 'border-indigo-600 bg-transparent text-indigo-700' : 'border-transparent bg-transparent text-slate-500 hover:bg-transparent hover:text-slate-900'}">Builds</Link>
        </div>

        <slot />
    </main>
</AppShell>

<EditAppModal {app} open={showEdit} onClose={closeEdit} />
<ManualBuildModal {app} {identifiers} {buildProfiles} open={showManual} onClose={closeManualBuild} />
<PipelineBuildModal pipeline={launchingPipeline} onClose={closePipelineBuild} />
