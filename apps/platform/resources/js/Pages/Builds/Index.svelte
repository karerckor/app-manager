<script>
    import { onDestroy, onMount } from 'svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Activity, Box, ChevronRight, Filter, RefreshCw, Search, X } from '@lucide/svelte';
    import WorkspacePage from '../../Components/WorkspacePage.svelte';
    import MultiSelectFilter from '../../Components/MultiSelectFilter.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let builds = { data: [], links: [], total: 0 };
    export let filters = {};
    export let filterOptions = { apps: [], identifiers: [], platforms: [], sources: [], statuses: [] };
    export let monitor = { total: 0, active: 0, failed: 0, completed: 0, success_rate: null, counts: {}, recent: [] };

    let query = filters.search ?? '';
    let selectedApps = filters.apps ?? [];
    let selectedIdentifiers = filters.identifiers ?? [];
    let selectedPlatforms = filters.platforms ?? [];
    let selectedSources = filters.sources ?? [];
    let selectedStatuses = filters.statuses ?? [];
    let startedFrom = filters.started_from ?? '';
    let startedTo = filters.started_to ?? '';
    let showFilters = false;
    let refreshing = false;
    let searchTimer;

    $: buildItems = builds?.data ?? [];
    $: pageLinks = (builds?.links ?? []).slice(1, -1);
    $: hasFilters = query.trim() !== ''
        || [selectedApps, selectedIdentifiers, selectedPlatforms, selectedSources, selectedStatuses].some((values) => values.length > 0)
        || startedFrom !== '' || startedTo !== '';
    $: activeFilterCount = [selectedApps, selectedIdentifiers, selectedPlatforms, selectedSources, selectedStatuses]
        .filter((values) => values.length > 0).length + (startedFrom !== '' || startedTo !== '' ? 1 : 0);

    const statusLabel = (status) => status.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    const statusDotClass = (status) => ({
        completed: 'bg-ok',
        running: 'bg-brand',
        pending: 'bg-muted',
        cancel_requested: 'bg-warn-fill',
        failed: 'bg-danger',
        cancelled: 'bg-faint',
    }[status] ?? 'bg-faint');
    const statusTextClass = (status) => ({
        completed: 'text-ok',
        running: 'text-brand',
        pending: 'text-muted',
        cancel_requested: 'text-warn-text',
        failed: 'text-danger',
        cancelled: 'text-faint',
    }[status] ?? 'text-faint');
    const sourceClass = (source) => ({
        pipeline: 'border-brand-soft-line bg-brand-soft text-brand',
        expo: 'border-ok/30 bg-ok/10 text-ok',
        manual: 'border-border bg-surface-sunken text-muted',
    }[source] ?? 'border-border bg-surface-sunken text-muted');
    const formatDateTime = (value) => value
        ? new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
        : '—';
    const formatDuration = (startedAt, finishedAt) => {
        if (!startedAt) return '—';
        if (!finishedAt) return 'In progress';

        const seconds = Math.max(0, Math.round((new Date(finishedAt) - new Date(startedAt)) / 1000));
        if (seconds < 60) return `${seconds}s`;

        const minutes = Math.floor(seconds / 60);
        const remainder = seconds % 60;
        if (minutes < 60) return remainder ? `${minutes}m ${remainder}s` : `${minutes}m`;

        const hours = Math.floor(minutes / 60);
        return `${hours}h ${minutes % 60}m`;
    };
    const initials = (name = '') => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();
    const visitFilters = () => {
        router.get(`/accounts/${navigationTeam.slug}/builds`, {
            search: query.trim() || undefined,
            apps: selectedApps.length ? selectedApps : undefined,
            identifiers: selectedIdentifiers.length ? selectedIdentifiers : undefined,
            platforms: selectedPlatforms.length ? selectedPlatforms : undefined,
            sources: selectedSources.length ? selectedSources : undefined,
            statuses: selectedStatuses.length ? selectedStatuses : undefined,
            started_from: startedFrom || undefined,
            started_to: startedTo || undefined,
        }, {
            only: ['builds', 'filters', 'monitor'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const scheduleSearch = () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(visitFilters, 350);
    };
    const setFilter = (name, values) => {
        if (name === 'apps') selectedApps = values;
        if (name === 'identifiers') selectedIdentifiers = values;
        if (name === 'platforms') selectedPlatforms = values;
        if (name === 'sources') selectedSources = values;
        if (name === 'statuses') selectedStatuses = values;
        visitFilters();
    };
    const focusStatus = (status) => {
        selectedStatuses = selectedStatuses.length === 1 && selectedStatuses[0] === status ? [] : [status];
        visitFilters();
    };
    const clearFilters = () => {
        query = '';
        selectedApps = [];
        selectedIdentifiers = [];
        selectedPlatforms = [];
        selectedSources = [];
        selectedStatuses = [];
        startedFrom = '';
        startedTo = '';
        visitFilters();
    };
    const refresh = () => {
        refreshing = true;
        router.reload({
            only: ['builds', 'monitor'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => refreshing = false,
        });
    };

    onMount(() => {
        const timer = setInterval(() => {
            if (monitor.active > 0) refresh();
        }, 5000);

        return () => clearInterval(timer);
    });
    onDestroy(() => clearTimeout(searchTimer));
</script>

<svelte:head><title>Builds · App Manager</title></svelte:head>

<WorkspacePage
    {auth}
    {flash}
    {navigationTeams}
    {navigationTeam}
    active="builds"
    eyebrow="Release operations"
    title="Builds"
    description="Monitor every application, platform and build runner from one place."
>
    {#snippet actions()}
        <button type="button" class="secondary gap-2" on:click={refresh} disabled={refreshing}>
            <RefreshCw class="h-4 w-4 {refreshing ? 'animate-spin' : ''}" strokeWidth={2} />
            {refreshing ? 'Refreshing…' : 'Refresh'}
        </button>
    {/snippet}

    <section class="mb-4 overflow-hidden rounded-md border border-nav-border bg-nav text-nav-strong" aria-labelledby="build-pulse-title">
        <div class="flex flex-wrap items-end justify-between gap-5 border-b border-nav-border px-5 py-4">
            <div>
                <div class="flex items-center gap-2">
                    <Activity class="h-4 w-4 text-brand" strokeWidth={2} />
                    <h2 id="build-pulse-title" class="font-condensed text-sm font-semibold tracking-[0.08em] uppercase">Build pulse</h2>
                    {#if monitor.active > 0}<span class="h-2 w-2 animate-pulse rounded-full bg-brand" aria-label="Live builds active"></span>{/if}
                </div>
                <p class="mt-1 text-xs text-nav-text">Latest runs in the current view. Select a status to isolate it.</p>
            </div>
            <dl class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <div><dt class="font-condensed text-2xs font-semibold tracking-[0.12em] text-nav-text uppercase">Visible</dt><dd class="mt-0.5 font-mono text-xl font-medium tabular-nums">{monitor.total}</dd></div>
                <div><dt class="font-condensed text-2xs font-semibold tracking-[0.12em] text-nav-text uppercase">Active</dt><dd class="mt-0.5 font-mono text-xl font-medium tabular-nums text-brand">{monitor.active}</dd></div>
                <div><dt class="font-condensed text-2xs font-semibold tracking-[0.12em] text-nav-text uppercase">Failed</dt><dd class="mt-0.5 font-mono text-xl font-medium tabular-nums {monitor.failed ? 'text-danger' : ''}">{monitor.failed}</dd></div>
                <div><dt class="font-condensed text-2xs font-semibold tracking-[0.12em] text-nav-text uppercase">Success</dt><dd class="mt-0.5 font-mono text-xl font-medium tabular-nums">{monitor.success_rate == null ? '—' : `${monitor.success_rate}%`}</dd></div>
            </dl>
        </div>

        <div class="px-5 py-4">
            {#if monitor.recent?.length}
                <div class="flex gap-1.5 overflow-x-auto pb-1" aria-label="Recent build status runway">
                    {#each monitor.recent as run (run.id)}
                        <Link
                            href={run.show_url}
                            class="group relative h-8 min-w-10 flex-1 overflow-hidden rounded-sm border border-nav-border bg-nav-border transition hover:-translate-y-0.5 hover:border-nav-text"
                            title={`${run.app_name} · ${run.platform?.toUpperCase() ?? 'No platform'} · ${statusLabel(run.status)}`}
                            aria-label={`${run.app_name}, ${run.platform ?? 'no platform'}, ${statusLabel(run.status)}`}
                        >
                            <span class="absolute inset-x-0 bottom-0 h-1.5 {statusDotClass(run.status)}"></span>
                            <span class="grid h-full place-items-center font-mono text-2xs font-medium text-nav-text group-hover:text-nav-strong">{initials(run.app_name)}</span>
                        </Link>
                    {/each}
                </div>
            {:else}
                <p class="py-1 text-xs text-nav-text">No builds in this view yet.</p>
            {/if}

            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2">
                {#each filterOptions.statuses as status (status.value)}
                    <button
                        type="button"
                        class="gap-1.5 bg-transparent p-0 text-2xs font-medium text-nav-text shadow-none hover:text-nav-strong"
                        class:text-nav-strong={selectedStatuses.includes(status.value)}
                        aria-pressed={selectedStatuses.includes(status.value)}
                        on:click={() => focusStatus(status.value)}
                    >
                        <span class="h-1.5 w-1.5 rounded-full {statusDotClass(status.value)}"></span>
                        {status.label}
                        <span class="font-mono text-nav-text/70">{monitor.counts?.[status.value] ?? 0}</span>
                    </button>
                {/each}
            </div>
        </div>
    </section>

    <section class="card overflow-clip p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
            <div>
                <h2 class="text-sm font-bold">Build history</h2>
                <p class="mt-0.5 font-mono text-2xs text-faint tabular-nums">{builds.total ?? 0} matching run{builds.total === 1 ? '' : 's'}</p>
            </div>
            <div class="flex w-full items-center gap-2 sm:w-auto">
                <label class="relative min-w-0 flex-1 sm:w-80 sm:flex-none">
                    <span class="sr-only">Search builds</span>
                    <Search class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-faint" />
                    <input class="py-2 pl-9 text-xs" bind:value={query} on:input={scheduleSearch} type="search" placeholder="App, identifier, pipeline or build ID…" />
                </label>
                <button type="button" class:secondary={!activeFilterCount} class="relative gap-1.5 px-3 py-2 text-xs" on:click={() => showFilters = !showFilters} aria-expanded={showFilters} aria-label="Filters">
                    <Filter class="h-3.5 w-3.5" strokeWidth={2} />
                    <span class="hidden sm:inline">Filters</span>
                    {#if activeFilterCount}<span class="rounded-full bg-brand-fg/20 px-1.5 font-mono text-2xs">{activeFilterCount}</span>{/if}
                </button>
            </div>
        </div>

        {#if showFilters || activeFilterCount}
            <div class="border-t border-border-subtle bg-surface-sunken/45 px-5 py-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <MultiSelectFilter label="Applications" options={filterOptions.apps} values={selectedApps} searchable searchPlaceholder="Search applications…" onChange={(values) => setFilter('apps', values)} />
                    <MultiSelectFilter label="Identifiers" options={filterOptions.identifiers} values={selectedIdentifiers} searchable searchPlaceholder="Search identifiers…" onChange={(values) => setFilter('identifiers', values)} />
                    <MultiSelectFilter label="Platforms" options={filterOptions.platforms} values={selectedPlatforms} onChange={(values) => setFilter('platforms', values)} />
                    <MultiSelectFilter label="Sources" options={filterOptions.sources} values={selectedSources} onChange={(values) => setFilter('sources', values)} />
                    <MultiSelectFilter label="Statuses" options={filterOptions.statuses} values={selectedStatuses} onChange={(values) => setFilter('statuses', values)} />
                    <div class="grid grid-cols-2 gap-2">
                        <label><span class="label">Started after</span><input class="py-2 text-xs" type="date" bind:value={startedFrom} on:change={visitFilters} /></label>
                        <label><span class="label">Started before</span><input class="py-2 text-xs" type="date" bind:value={startedTo} on:change={visitFilters} /></label>
                    </div>
                </div>
                <div class="mt-3 flex justify-end border-t border-border-subtle pt-3">
                    {#if hasFilters}<button type="button" class="ghost gap-1.5 px-2 py-1.5 text-xs" on:click={clearFilters}><X class="h-3.5 w-3.5" />Clear all</button>{/if}
                </div>
            </div>
        {/if}

        {#if buildItems.length}
            <div class="divide-y divide-border-subtle border-t border-border-subtle lg:hidden">
                {#each buildItems as build (build.id)}
                    <article class="px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="relative grid h-9 w-9 shrink-0 place-items-center rounded-sm border border-border bg-surface-raised font-condensed text-2xs font-semibold text-muted">
                                    {initials(build.app.name)}
                                    <span class="absolute inset-y-1 -left-px w-1 rounded-r-sm {statusDotClass(build.status)}"></span>
                                </span>
                                <div class="min-w-0">
                                    <Link href={build.app.url} class="block truncate text-sm font-semibold text-text hover:text-brand">{build.app.name}</Link>
                                    <p class="mt-0.5 truncate font-mono text-2xs text-faint" title={build.request_uuid}>{build.request_uuid}</p>
                                </div>
                            </div>
                            <span class="inline-flex shrink-0 items-center gap-1 text-2xs font-semibold {statusTextClass(build.status)}"><span class="h-1.5 w-1.5 rounded-full {statusDotClass(build.status)}"></span>{statusLabel(build.status)}</span>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-y border-border-subtle py-3 text-xs">
                            <div class="min-w-0"><dt class="font-condensed text-2xs font-semibold tracking-[0.08em] text-faint uppercase">Target</dt><dd class="mt-1 truncate text-muted">{build.identifier ? `${build.identifier.platform_label} · ${build.identifier.identifier}` : 'No identifier'}</dd></div>
                            <div class="min-w-0"><dt class="font-condensed text-2xs font-semibold tracking-[0.08em] text-faint uppercase">Source</dt><dd class="mt-1 truncate text-muted">{build.source_label}</dd></div>
                            <div><dt class="font-condensed text-2xs font-semibold tracking-[0.08em] text-faint uppercase">Started</dt><dd class="mt-1 text-muted">{formatDateTime(build.started_at || build.created_at)}</dd></div>
                            <div><dt class="font-condensed text-2xs font-semibold tracking-[0.08em] text-faint uppercase">Duration</dt><dd class="mt-1 font-mono text-2xs">{formatDuration(build.started_at, build.finished_at)}</dd></div>
                        </dl>

                        <div class="mt-3 flex items-center justify-between">
                            <span class="inline-flex items-center gap-1.5 text-xs {build.artifacts_count ? 'text-text' : 'text-faint'}"><Box class="h-3.5 w-3.5" strokeWidth={1.8} />{build.artifacts_count} artifact{build.artifacts_count === 1 ? '' : 's'}</span>
                            <Link href={build.show_url} class="inline-flex items-center gap-1 text-xs font-semibold text-muted hover:text-brand">Details<ChevronRight class="h-3.5 w-3.5" /></Link>
                        </div>
                    </article>
                {/each}
            </div>

            <div class="hidden overflow-x-auto border-t border-border-subtle lg:block">
                <table class="w-full min-w-[980px] text-left text-sm">
                    <thead class="bg-surface-sunken font-condensed text-2xs font-semibold tracking-[0.1em] text-faint uppercase">
                        <tr>
                            <th class="px-5 py-2.5">Build / application</th>
                            <th class="px-4 py-2.5">Target</th>
                            <th class="px-4 py-2.5">Source</th>
                            <th class="px-4 py-2.5">Profile</th>
                            <th class="px-4 py-2.5">Started</th>
                            <th class="px-4 py-2.5">Duration</th>
                            <th class="px-4 py-2.5">Artifacts</th>
                            <th class="px-5 py-2.5"><span class="sr-only">Open build</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-subtle">
                        {#each buildItems as build (build.id)}
                            <tr class="group align-middle transition hover:bg-surface-sunken/55">
                                <td class="px-5 py-3.5">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="relative grid h-9 w-9 shrink-0 place-items-center rounded-sm border border-border bg-surface-raised font-condensed text-2xs font-semibold text-muted">
                                            {initials(build.app.name)}
                                            <span class="absolute inset-y-1 -left-px w-1 rounded-r-sm {statusDotClass(build.status)}"></span>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <Link href={build.app.url} class="truncate font-semibold text-text hover:text-brand">{build.app.name}</Link>
                                                <span class="inline-flex shrink-0 items-center gap-1 text-2xs font-semibold {statusTextClass(build.status)}"><span class="h-1.5 w-1.5 rounded-full {statusDotClass(build.status)}"></span>{statusLabel(build.status)}</span>
                                            </div>
                                            <p class="mt-0.5 truncate font-mono text-2xs text-faint" title={build.request_uuid}>{build.request_uuid}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5">
                                    {#if build.identifier}
                                        <Link href={build.identifier.url} class="block max-w-56 hover:text-brand">
                                            <span class="block text-xs font-semibold">{build.identifier.platform_label}</span>
                                            <code class="mt-0.5 block truncate text-2xs text-faint">{build.identifier.identifier}</code>
                                        </Link>
                                    {:else}<span class="text-xs text-faint">No identifier</span>{/if}
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex max-w-44 items-center rounded-sm border px-2 py-1 text-2xs font-semibold {sourceClass(build.source)}">
                                        <span class="truncate">{build.source_label}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-xs text-muted">{build.profile_type}</td>
                                <td class="px-4 py-3.5 text-xs text-muted"><time datetime={build.started_at || build.created_at}>{formatDateTime(build.started_at || build.created_at)}</time></td>
                                <td class="px-4 py-3.5 font-mono text-2xs tabular-nums {build.finished_at ? 'text-text' : 'text-faint'}">{formatDuration(build.started_at, build.finished_at)}</td>
                                <td class="px-4 py-3.5"><span class="inline-flex items-center gap-1.5 text-xs {build.artifacts_count ? 'text-text' : 'text-faint'}"><Box class="h-3.5 w-3.5" strokeWidth={1.8} />{build.artifacts_count}</span></td>
                                <td class="px-5 py-3.5 text-right"><Link href={build.show_url} class="inline-flex items-center gap-1 text-xs font-semibold text-muted hover:text-brand">Details<ChevronRight class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" /></Link></td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>

            {#if builds.last_page > 1}
                <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle px-5 py-4" aria-label="Build pagination">
                    <p class="font-mono text-2xs text-faint tabular-nums">{builds.from}–{builds.to} of {builds.total}</p>
                    <div class="flex items-center gap-1.5">
                        {#if builds.prev_page_url}<Link href={builds.prev_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}
                        {#each pageLinks as link}
                            {#if link.url}<Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center text-xs {link.active ? 'border-brand-soft-line bg-brand-soft text-brand' : ''}" preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>{:else}<span class="px-1 text-xs text-faint">{link.label}</span>{/if}
                        {/each}
                        {#if builds.next_page_url}<Link href={builds.next_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>{/if}
                    </div>
                </nav>
            {/if}
        {:else}
            <div class="border-t border-border-subtle px-6 py-16 text-center">
                <span class="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-md bg-brand-soft text-brand"><Activity class="h-5 w-5" strokeWidth={1.8} /></span>
                <h3 class="text-sm font-bold">{hasFilters ? 'No builds match these filters' : 'No builds yet'}</h3>
                <p class="mx-auto mt-2 max-w-sm text-sm text-muted">{hasFilters ? 'Change the target, source or status to widen the view.' : 'Manual, pipeline and Expo builds will appear here as soon as they are reported.'}</p>
                {#if hasFilters}<button type="button" class="secondary mt-5" on:click={clearFilters}>Clear filters</button>{/if}
            </div>
        {/if}
    </section>
</WorkspacePage>
