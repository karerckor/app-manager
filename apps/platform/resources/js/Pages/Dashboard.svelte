<script>
    import { onDestroy } from 'svelte';
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { Plus, LayoutGrid, Cpu, KeyRound, Search, ChevronRight, SquarePlus, SlidersHorizontal, X } from '@lucide/svelte';
    import WorkspacePage from '../Components/WorkspacePage.svelte';
    import TabSelector from '../Components/TabSelector.svelte';
    import TagEditor from '../Components/TagEditor.svelte';
    import MultiSelectFilter from '../Components/MultiSelectFilter.svelte';
    import { parseMetadata } from './Apps/lib.js';

    export let auth;
    export let flash = {};
    export let apps = { data: [], links: [], total: 0 };
    export let filters = {};
    export let stats = { apps: 0, identifiers: 0, credentials: 0 };
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let createAppUrl;
    export let filterOptions = { tags: [], platforms: [], credentials: [] };

    let query = filters.search ?? '';
    let selectedTags = filters.tags ?? [];
    let availablePlatforms = filters.available_platforms ?? [];
    let unavailablePlatforms = filters.unavailable_platforms ?? [];
    let expo = filters.expo ?? [];
    let autoSync = filters.auto_sync ?? false;
    let selectedCredentials = filters.credentials ?? [];
    let missingCredentials = filters.missing_credentials ?? [];
    let showFilters = false;
    let searchTimer;
    let showCreate = false;
    let metadataText = '';
    let metadataError = '';
    const form = useForm({ name: '', slug: '', description: '', metadata: null });

    $: appItems = apps?.data ?? [];
    $: pageLinks = (apps?.links ?? []).slice(1, -1);
    $: hasAdvancedFilters = [selectedTags, availablePlatforms, unavailablePlatforms, selectedCredentials, missingCredentials].some((values) => values.length > 0) || expo.length > 0 || autoSync;
    $: expoFilterOptions = [
        { value: 'any_connection', label: 'Any connection' },
        { value: 'not_connected', label: 'Not connected' },
        { value: 'detached', label: 'Detached' },
        ...(filterOptions.expoAccounts ?? []),
    ];

    const createApp = () => {
        const metadata = parseMetadata(metadataText, (message) => metadataError = message);

        if (metadata === undefined) return;

        form.metadata = metadata;
        form.post(createAppUrl, {
            onSuccess: () => {
                form.reset();
                metadataText = '';
                metadataError = '';
                showCreate = false;
            },
        });
    };
    const closeModal = () => {
        showCreate = false;
        metadataError = '';
        form.clearErrors();
    };
    const scheduleSearch = () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            visitFilters();
        }, 350);
    };
    const visitFilters = () => {
        router.get(apps.path, {
            search: query.trim() || undefined,
            tags: selectedTags.length ? selectedTags : undefined,
            available_platforms: availablePlatforms.length ? availablePlatforms : undefined,
            unavailable_platforms: unavailablePlatforms.length ? unavailablePlatforms : undefined,
            expo: expo.length ? expo : undefined,
            auto_sync: autoSync || undefined,
            credentials: selectedCredentials.length ? selectedCredentials : undefined,
            missing_credentials: missingCredentials.length ? missingCredentials : undefined,
        }, {
            only: ['apps', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const clearAdvancedFilters = () => {
        selectedTags = [];
        availablePlatforms = [];
        unavailablePlatforms = [];
        expo = [];
        autoSync = false;
        selectedCredentials = [];
        missingCredentials = [];
        visitFilters();
    };
    const formatDate = (value) => new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric' }).format(new Date(value));
    const initials = (name) => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();

    onDestroy(() => clearTimeout(searchTimer));
</script>

<svelte:head><title>Applications · App Manager</title></svelte:head>

<WorkspacePage {auth} {navigationTeams} {navigationTeam} active="apps" eyebrow="Workspace catalog" title="Applications" {flash}>
    {#snippet actions()}
        <button class="flex items-center gap-2" on:click={() => showCreate = true}>
            <Plus class="h-4 w-4" strokeWidth={2.2} />
            New application
        </button>
    {/snippet}

        <div class="mb-4">
            <TabSelector
                variant="pills"
                ariaLabel="Workspace stats"
                items={[
                    { value: 'apps', label: 'Applications', count: stats.apps, icon: LayoutGrid },
                    { value: 'identifiers', label: 'Identifiers', count: stats.identifiers, icon: Cpu },
                    { value: 'credentials', label: 'Credentials', count: stats.credentials, icon: KeyRound },
                ]}
            />
        </div>

        <section class="card overflow-clip p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <h2 class="text-xs font-bold text-text">
                    Workspace applications
                    <span class="ml-1 font-mono text-[11px] font-medium text-faint tabular-nums">{apps.total ?? 0}{filters.search || hasAdvancedFilters ? ' matching' : ''} in {navigationTeam?.name ?? 'current workspace'}</span>
                </h2>
                <div class="flex w-full items-center gap-2 sm:w-auto">
                    <label class="relative min-w-0 flex-1 sm:w-72 sm:flex-none">
                        <span class="sr-only">Search applications</span>
                        <Search class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-faint" />
                        <input class="py-2 pl-9 text-xs" bind:value={query} on:input={scheduleSearch} type="search" placeholder="Search name, slug, tag…" />
                    </label>
                    <button type="button" class:secondary={!hasAdvancedFilters} class="relative px-3 py-2 text-xs" on:click={() => showFilters = !showFilters} aria-expanded={showFilters}>
                        <SlidersHorizontal class="h-3.5 w-3.5" strokeWidth={2} />
                        <span class="hidden sm:inline">Filters</span>
                        {#if hasAdvancedFilters}<span class="absolute -top-1 -right-1 h-2.5 w-2.5 rounded-full bg-brand ring-2 ring-surface-raised"></span>{/if}
                    </button>
                </div>
            </div>

            {#if showFilters}
                <div class="border-t border-border-subtle bg-surface-sunken/45 px-5 py-4">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <MultiSelectFilter label="Tags" options={filterOptions.tags ?? []} values={selectedTags} searchable searchPlaceholder="Search tags…" onChange={(values) => { selectedTags = values; visitFilters(); }} />
                        <MultiSelectFilter label="Platforms available" options={filterOptions.platforms ?? []} values={availablePlatforms} onChange={(values) => { availablePlatforms = values; visitFilters(); }} />
                        <MultiSelectFilter label="Platforms unavailable" options={filterOptions.platforms ?? []} values={unavailablePlatforms} onChange={(values) => { unavailablePlatforms = values; visitFilters(); }} />
                        <MultiSelectFilter label="Expo" options={expoFilterOptions} values={expo} onChange={(values) => { expo = values; visitFilters(); }} />
                        <label class="flex items-center gap-2 self-end pb-2">
                            <input type="checkbox" class="h-4 w-4 rounded border-border-control text-brand accent-brand focus:ring-brand/30" bind:checked={autoSync} on:change={visitFilters} />
                            <span class="label mb-0">Auto-sync enabled</span>
                        </label>
                        <MultiSelectFilter label="Credentials present" options={filterOptions.credentials ?? []} values={selectedCredentials} searchable searchPlaceholder="Search credential types…" onChange={(values) => { selectedCredentials = values; visitFilters(); }} />
                        <MultiSelectFilter label="Credentials missing" options={filterOptions.credentials ?? []} values={missingCredentials} searchable searchPlaceholder="Search credential types…" onChange={(values) => { missingCredentials = values; visitFilters(); }} />
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-3 border-t border-border-subtle pt-3">
                        <p class="text-[11px] text-faint">Each selected credential type must be present or missing in the application.</p>
                        {#if hasAdvancedFilters}<button type="button" class="ghost shrink-0 px-2 py-1.5 text-xs" on:click={clearAdvancedFilters}><X class="h-3.5 w-3.5" />Clear</button>{/if}
                    </div>
                </div>
            {/if}

            {#if appItems.length}
                <div class="hidden border-y border-border-subtle bg-surface-sunken px-5 py-2 text-[10px] font-bold tracking-[0.1em] text-faint uppercase sm:grid sm:grid-cols-[minmax(0,1fr)_90px_70px_16px] sm:items-center sm:gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(150px,0.55fr)_120px_90px_16px] xl:gap-4">
                    <span>Application</span>
                    <span class="hidden xl:block">Tags</span>
                    <span>Identifiers</span>
                    <span>Updated</span>
                    <span class="sr-only">Open</span>
                </div>
                <div class="divide-y divide-border-subtle">
                    {#each appItems as app (app.id)}
                        <Link href={app.url} class="group grid gap-3 px-5 py-3 transition hover:bg-surface-sunken sm:grid-cols-[minmax(0,1fr)_90px_70px_16px] sm:items-center xl:grid-cols-[minmax(0,1fr)_minmax(150px,0.55fr)_120px_90px_16px] xl:gap-4">
                            <div class="flex min-w-0 items-center gap-3">
                                {#if app.icon_url}<img src={app.icon_url} alt="" class="h-8 w-8 shrink-0 rounded-lg bg-surface-raised object-cover ring-1 ring-border" />{:else}<span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-brand to-brand text-[10px] font-bold text-white">{initials(app.name)}</span>{/if}
                                <div class="min-w-0">
                                    <h3 class="truncate text-[13px] font-semibold text-text group-hover:text-brand">{app.name}</h3>
                                    <p class="mt-0.5 truncate text-[11px] text-faint"><code>{app.slug}</code>{#if app.description}<span class="font-sans"> · {app.description}</span>{/if}</p>
                                </div>
                            </div>
                            <div class="hidden min-w-0 xl:block">
                                {#if app.tags?.length}<TagEditor tags={app.tags} disabled quiet />{:else}<span class="text-[11px] text-faint">No tags</span>{/if}
                            </div>
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-medium text-muted"><Cpu class="h-3.5 w-3.5 text-faint" strokeWidth={1.8} />{app.identifiers_count} identifier{app.identifiers_count === 1 ? '' : 's'}</span>
                            <span class="text-[11px] text-faint">{formatDate(app.updated_at)}</span>
                            <ChevronRight class="h-4 w-4 shrink-0 text-faint transition group-hover:translate-x-0.5 group-hover:text-brand" />
                        </Link>
                    {/each}
                </div>

                {#if apps.last_page > 1}
                    <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle px-5 py-4" aria-label="Application pagination">
                        <p class="font-mono text-[11px] text-faint tabular-nums">{apps.from}–{apps.to} of {apps.total}</p>
                        <div class="flex items-center gap-1.5">
                            {#if apps.prev_page_url}
                                <Link href={apps.prev_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>
                            {:else}
                                <span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>
                            {/if}

                            {#each pageLinks as link}
                                {#if link.url}
                                    <Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center text-xs {link.active ? 'border-brand-soft-line bg-brand-soft text-brand' : ''}" preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>
                                {:else}
                                    <span class="px-1 text-xs text-faint">{link.label}</span>
                                {/if}
                            {/each}

                            {#if apps.next_page_url}
                                <Link href={apps.next_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>
                            {:else}
                                <span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>
                            {/if}
                        </div>
                    </nav>
                {/if}
            {:else}
                <div class="border-t border-border-subtle px-6 py-16 text-center"><span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-md bg-brand-soft text-brand"><SquarePlus class="h-6 w-6" strokeWidth={1.7} /></span><h3 class="text-sm font-bold">{filters.search || hasAdvancedFilters ? 'No matching applications' : 'Create your first application'}</h3><p class="mx-auto mt-2 max-w-sm text-sm text-muted">{filters.search || hasAdvancedFilters ? 'Try another search term or clear the filters.' : 'Applications group your platform identifiers, signing credentials, and assets.'}</p>{#if hasAdvancedFilters}<button class="secondary mt-5" on:click={clearAdvancedFilters}>Clear filters</button>{:else if !filters.search}<button class="mt-5" on:click={() => showCreate = true}>New application</button>{/if}</div>
            {/if}
        </section>
</WorkspacePage>

{#if showCreate}
    <div class="modal-backdrop" role="presentation" on:click={(event) => event.target === event.currentTarget && closeModal()}>
        <form class="modal" on:submit|preventDefault={createApp}>
            <div class="flex items-start justify-between border-b border-border-subtle px-6 py-5"><div><h2 class="text-lg font-bold">New application</h2><p class="mt-1 text-sm text-muted">Set up the project. Identifiers can be added next.</p></div><button type="button" class="ghost -mr-2 p-2" on:click={closeModal} aria-label="Close">✕</button></div>
            <div class="space-y-5 p-6">
                <label><span class="label">Application name</span><input bind:value={form.name} placeholder="e.g. Atlas Mobile" required />{#if form.errors.name}<span class="error">{form.errors.name}</span>{/if}</label>
                <label><span class="label">Slug <span class="font-normal text-faint">(optional)</span></span><input bind:value={form.slug} placeholder="Generated from application name" />{#if form.errors.slug}<span class="error">{form.errors.slug}</span>{/if}</label>
                <label><span class="label">Description <span class="font-normal text-faint">(optional)</span></span><textarea bind:value={form.description} placeholder="What is this application for?"></textarea>{#if form.errors.description}<span class="error">{form.errors.description}</span>{/if}</label>
                <label><span class="label">Metadata (JSON) <span class="font-normal text-faint">(optional)</span></span><textarea class="min-h-36 font-mono text-xs leading-5" bind:value={metadataText} spellcheck="false" placeholder={'{\n  "owner": "mobile-team",\n  "channel": "production"\n}'}></textarea><span class="mt-1 block text-[10px] text-faint">Add any JSON object with application-specific values.</span>{#if metadataError || form.errors.metadata}<span class="error">{metadataError || form.errors.metadata}</span>{/if}</label>
            </div>
            <div class="flex justify-end gap-3 border-t border-border bg-surface-sunken px-6 py-4"><button type="button" class="secondary" on:click={closeModal}>Cancel</button><button disabled={form.processing}>{form.processing ? 'Creating…' : 'Create application'}</button></div>
        </form>
    </div>
{/if}
