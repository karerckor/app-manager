<script>
    import { Link, router } from '@inertiajs/svelte';
    import { Activity, AlertTriangle, ArrowUpRight, CircleCheck, Clock3, Fingerprint, Layers3, Search, Settings2, X } from '@lucide/svelte';
    import MultiSelectFilter from '../../../Components/MultiSelectFilter.svelte';
    import Select from '../../../Components/Select.svelte';
    import TagEditor from '../../../Components/TagEditor.svelte';
    import AppLayout from '../AppLayout.svelte';
    import { formatDateTime } from '../lib.js';

    let { auth, flash = {}, app, pipelines = [], identifiers = [], buildProfiles = [], tag_suggestions = [], navigationTeams = [], navigationTeam = null, active = 'telemetry', telemetry = { issues: [], traces: [], stats: {}, filters: {}, environments: [] } } = $props();
    let search = $state('');
    $effect(() => { search = telemetry.filters.search ?? ''; });
    const isTraceView = $derived(telemetry.filters.view === 'traces');
    const identifierOptions = $derived(identifiers.map((identifier) => ({ value: identifier.id, label: `${({ ios: 'iOS', android: 'Android', web: 'Web' }[identifier.platform] ?? identifier.platform)} · ${identifier.identifier}` })));
    const environmentOptions = $derived([{ value: '', label: 'All environments' }, ...telemetry.environments.map((environment) => ({ value: environment, label: environment }))]);
    const shortId = (value) => value ? `${value.slice(0, 8)}…${value.slice(-6)}` : '—';
    const issueRailClass = (count) => count >= 20 ? 'bg-danger' : count >= 5 ? 'bg-danger/65' : 'bg-danger/35';
    const visit = (updates = {}) => {
        const selectedIdentifiers = updates.identifiers ?? telemetry.filters.identifiers ?? [];
        router.get(app.urls.telemetry_page, { view: updates.view ?? telemetry.filters.view, environment: updates.environment ?? telemetry.filters.environment, identifiers: selectedIdentifiers.length ? selectedIdentifiers : undefined, search: updates.search ?? telemetry.filters.search }, { preserveState: true, preserveScroll: true, replace: true });
    };
</script>

<svelte:head><title>Errors & traces · {app.name} · App Manager</title></svelte:head>

<AppLayout {auth} {flash} {app} {pipelines} {identifiers} {buildProfiles} tagSuggestions={tag_suggestions} {navigationTeams} {navigationTeam} {active}>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div><h2 class="text-xl font-extrabold tracking-tight text-text">Errors & traces</h2><p class="mt-1 max-w-2xl text-xs leading-5 text-muted">Triage telemetry resolved to this application. Open an issue or trace to inspect the complete event payload.</p></div>
        {#if telemetry.settings_url}<Link href={telemetry.settings_url} class="secondary inline-flex items-center gap-2 px-3 py-2 text-xs"><Settings2 class="h-3.5 w-3.5" />Configure Sentry</Link>{/if}
    </div>

    <section class="card mb-5 grid overflow-hidden p-0 sm:grid-cols-2 xl:grid-cols-4" aria-label="Telemetry summary">
        <div class="flex items-center gap-3 border-b border-border-subtle px-4 py-3.5 sm:border-r xl:border-b-0"><span class="grid h-8 w-8 place-items-center rounded-md bg-danger/10 text-danger"><AlertTriangle class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold text-text">{telemetry.stats.issues}</p><p class="text-[10px] text-muted">Error issues</p></div></div>
        <div class="flex items-center gap-3 border-b border-border-subtle px-4 py-3.5 xl:border-r xl:border-b-0"><span class="grid h-8 w-8 place-items-center rounded-md bg-danger/10 text-danger"><Activity class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold text-text">{telemetry.stats.errors}</p><p class="text-[10px] text-muted">Error events</p></div></div>
        <div class="flex items-center gap-3 border-b border-border-subtle px-4 py-3.5 sm:border-r sm:border-b-0"><span class="grid h-8 w-8 place-items-center rounded-md bg-surface-sunken text-muted"><Layers3 class="h-4 w-4" /></span><div><p class="font-mono text-base font-bold text-text">{telemetry.stats.traces}</p><p class="text-[10px] text-muted">Distinct traces</p></div></div>
        <div class="flex items-center gap-3 px-4 py-3.5"><span class="grid h-8 w-8 place-items-center rounded-md bg-surface-sunken text-muted"><Clock3 class="h-4 w-4" /></span><div><p class="text-xs font-semibold text-text">{telemetry.stats.last_seen_at ? formatDateTime(telemetry.stats.last_seen_at) : 'No events yet'}</p><p class="text-[10px] text-muted">Last received</p></div></div>
    </section>

    <section class="card relative z-10 mb-5 overflow-visible p-0">
        <div class="flex flex-col gap-3 border-b border-border-subtle px-4 py-3.5 lg:flex-row lg:items-center lg:justify-between"><div class="inline-flex w-fit rounded-md bg-surface-sunken p-0.5" role="tablist">{#each [['issues', 'Issues', telemetry.stats.issues], ['traces', 'Traces', telemetry.stats.traces]] as item}<button type="button" role="tab" aria-selected={telemetry.filters.view === item[0]} class="rounded-sm px-3 py-1.5 text-[10px] font-semibold {telemetry.filters.view === item[0] ? 'bg-surface-raised text-text' : 'bg-transparent text-muted hover:text-text'}" onclick={() => visit({ view: item[0] })}>{item[1]} <span class="ml-1 font-mono text-[9px] text-faint">{item[2]}</span></button>{/each}</div></div>
        <div class="grid gap-3 bg-surface-sunken/40 px-4 py-3.5 md:grid-cols-2 xl:grid-cols-[minmax(240px,1fr)_180px_minmax(260px,1.1fr)] xl:items-end">
            <MultiSelectFilter label="App identifiers & domains" options={identifierOptions} values={telemetry.filters.identifiers ?? []} searchable searchPlaceholder="Search platform or identifier…" onChange={(values) => visit({ identifiers: values })} />
            <label><span class="label">Environment</span><Select value={telemetry.filters.environment} options={environmentOptions} ariaLabel="Environment" on:change={(event) => visit({ environment: event.detail })} /></label>
            <form class="min-w-0 md:col-span-2 xl:col-span-1" onsubmit={(event) => { event.preventDefault(); visit({ search: search.trim() }); }}><label class="label" for="telemetry-search">Search</label><div class="relative"><Search class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-faint" /><input id="telemetry-search" class="w-full py-2 pr-9 pl-9 text-xs" bind:value={search} placeholder="Title, release, event or trace ID" />{#if search}<button type="button" class="ghost absolute top-1/2 right-1.5 grid h-7 w-7 -translate-y-1/2 place-items-center p-0" aria-label="Clear search" onclick={() => { search = ''; visit({ search: '' }); }}><X class="h-3.5 w-3.5" /></button>{/if}</div></form>
        </div>
    </section>

    {#if (isTraceView ? telemetry.traces : telemetry.issues).length}
        <section class="card overflow-hidden p-0" aria-label={isTraceView ? 'Traces' : 'Error issues'}>
            <div class="flex items-center justify-between gap-3 border-b border-border-subtle px-4 py-3"><div><h3 class="text-xs font-bold text-text">{isTraceView ? 'Trace stream' : 'Issue inbox'}</h3><p class="mt-0.5 text-[10px] text-muted">{isTraceView ? 'One row per distributed trace' : 'Same fingerprint · one issue · many occurrences'}</p></div><span class="font-mono text-[10px] text-faint">{Math.min(telemetry.matching_count, telemetry.limit)} / {telemetry.matching_count}</span></div>
            <div class="divide-y divide-border-subtle">
                {#if isTraceView}{#each telemetry.traces as trace (trace.trace_id)}<Link href={trace.url} class="group relative grid grid-cols-[auto_minmax(0,1fr)_auto] gap-3 bg-surface-raised px-4 py-3.5 text-text transition hover:bg-surface-sunken"><span class="absolute inset-y-0 left-0 w-0.5 bg-transparent transition group-hover:bg-brand"></span><span class="mt-0.5 grid h-8 w-8 place-items-center rounded-md bg-brand-soft text-brand"><Activity class="h-4 w-4" /></span><span class="min-w-0"><span class="flex items-center gap-2"><strong class="truncate text-xs font-semibold">{trace.title}</strong><span class="rounded-full bg-brand-soft px-1.5 py-0.5 text-[8px] font-bold uppercase text-brand">Trace</span></span><span class="mt-1 flex flex-wrap gap-x-2 text-[9px] text-faint">{#if trace.environment}<span>{trace.environment}</span>{/if}{#if trace.identifier}<span class="font-mono">{trace.identifier}</span>{/if}<span class="inline-flex items-center gap-1 font-mono"><Fingerprint class="h-2.5 w-2.5" />{shortId(trace.trace_id)}</span></span></span><time class="mt-0.5 whitespace-nowrap text-[9px] text-faint">{formatDateTime(trace.occurred_at)}</time></Link>{/each}{:else}{#each telemetry.issues as issue (issue.id)}<Link href={issue.url} class="group relative grid grid-cols-[auto_minmax(0,1fr)_auto] gap-3 bg-surface-raised px-4 py-4 text-text transition hover:bg-surface-sunken"><span class="absolute inset-y-0 left-0 w-1 {issue.status === 'resolved' ? 'bg-ok' : issueRailClass(issue.events_count)} opacity-40 transition group-hover:opacity-100"></span><span class="mt-0.5 grid h-8 w-8 place-items-center rounded-md {issue.status === 'resolved' ? 'bg-ok/10 text-ok' : 'bg-danger/10 text-danger'}">{#if issue.status === 'resolved'}<CircleCheck class="h-4 w-4" />{:else}<AlertTriangle class="h-4 w-4" />{/if}</span><span class="min-w-0"><span class="flex items-center gap-2"><strong class="truncate text-xs font-semibold">{issue.title}</strong><span class="rounded-full px-1.5 py-0.5 text-[8px] font-bold uppercase {issue.status === 'resolved' ? 'bg-ok/10 text-ok' : 'bg-danger/10 text-danger'}">{issue.status === 'resolved' ? 'Resolved' : 'Issue'}</span></span><span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[9px] text-faint">{#if issue.identifier}<span class="font-mono">{issue.identifier}</span>{/if}<span>First {formatDateTime(issue.first_seen_at)}</span>{#if issue.tags?.length}<TagEditor tags={issue.tags} disabled quiet />{/if}</span></span><span class="text-right"><strong class="block font-mono text-sm {issue.status === 'resolved' ? 'text-ok' : 'text-danger'}">{issue.events_count}</strong><span class="text-[8px] font-semibold tracking-[0.08em] text-faint uppercase">events</span><time class="mt-1 block whitespace-nowrap text-[9px] text-faint">{formatDateTime(issue.last_seen_at)}</time></span></Link>{/each}{/if}
            </div>
        </section>
    {:else}
        <section class="card py-14 text-center"><span class="mx-auto grid h-14 w-14 place-items-center rounded-md bg-brand-soft text-brand"><Activity class="h-6 w-6" /></span><h3 class="mt-4 text-sm font-bold">{telemetry.stats.errors || telemetry.stats.traces ? 'No telemetry matches these filters' : 'No telemetry for this application yet'}</h3><p class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted">{telemetry.stats.errors || telemetry.stats.traces ? 'Change the view, environment, identifier, or search query to widen the results.' : 'Point a Sentry SDK at a routed DSN for this application.'}</p>{#if telemetry.stats.errors || telemetry.stats.traces}<button type="button" class="ghost mt-4 px-3 py-2 text-xs" onclick={() => { search = ''; visit({ view: 'issues', environment: '', identifiers: [], search: '' }); }}>Clear filters</button>{:else if telemetry.settings_url}<Link href={telemetry.settings_url} class="mt-5 inline-flex items-center gap-2 text-xs font-semibold text-brand">Configure a routed DSN <ArrowUpRight class="h-3.5 w-3.5" /></Link>{/if}</section>
    {/if}
</AppLayout>
