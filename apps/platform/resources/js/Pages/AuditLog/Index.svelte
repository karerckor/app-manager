<script>
    import { Link, router } from '@inertiajs/svelte';
    import {
        Activity,
        ArrowUpRight,
        CirclePlus,
        PencilLine,
        RotateCcw,
        ScrollText,
        ShieldCheck,
        Trash2,
        UserMinus,
        UserPlus,
    } from '@lucide/svelte';
    import AuditTagChange from '../../Components/AuditTagChange.svelte';
    import WorkspacePage from '../../Components/WorkspacePage.svelte';
    import MultiSelectFilter from '../../Components/MultiSelectFilter.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let audits = {};
    export let filters = {};
    export let filterOptions = { apps: [], users: [], events: [], sources: [] };

    let selectedApps = filters.apps ?? [];
    let selectedActors = filters.actors ?? [];
    let selectedEvents = filters.events ?? [];
    let selectedSources = filters.sources ?? [];
    let selectedFrom = filters.from ?? '';
    let selectedTo = filters.to ?? '';

    $: hasFilters = [selectedApps, selectedActors, selectedEvents, selectedSources].some((values) => values.length > 0)
        || Boolean(selectedFrom || selectedTo);
    $: pageLinks = (audits.links ?? []).slice(1, -1);
    $: paginationKey = (audits.links ?? [])
        .map((link) => link.url ?? link.label)
        .join('|');
    $: appOptions = (filterOptions.apps ?? []).map((app) => ({ value: app.id, label: app.name }));
    $: actorOptions = (filterOptions.users ?? []).map((user) => ({ value: user.id, label: user.name }));
    $: eventOptions = (filterOptions.events ?? []).map((event) => ({ value: event, label: eventLabel(event) }));
    $: sourceOptions = (filterOptions.sources ?? []).map((source) => ({ value: source, label: sourceLabel(source) }));

    const formatDay = (value) => value
        ? new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value))
        : '—';
    const formatTime = (value) => value
        ? new Intl.DateTimeFormat('en', { hour: 'numeric', minute: '2-digit' }).format(new Date(value))
        : '';
    const formatFullDate = (value) => value
        ? new Intl.DateTimeFormat('en', { dateStyle: 'full', timeStyle: 'long' }).format(new Date(value))
        : '';
    const formatChange = (change) => {
        if (change.mode === 'added') return change.to;
        if (change.mode === 'removed') return `${change.from} → Removed`;
        return `${change.from} → ${change.to}`;
    };
    const eventLabel = (event) => event
        ? event.replaceAll('_', ' ').replace(/^\w/, (letter) => letter.toUpperCase())
        : 'All';
    const eventIcon = (event) => ({
        created: CirclePlus,
        restored: RotateCcw,
        deleted: Trash2,
        member_added: UserPlus,
        member_removed: UserMinus,
        updated: PencilLine,
    }[event] ?? Activity);
    const eventMarkClass = (event) => {
        if (event === 'created' || event === 'member_added') return 'bg-ok/10 text-ok ring-ok/30';
        if (event === 'deleted' || event === 'member_removed') return 'bg-danger/10 text-danger ring-danger/30';
        if (event === 'restored') return 'bg-brand-soft text-brand ring-brand/30';
        if (event === 'updated') return 'bg-brand-soft text-brand ring-brand-soft-line';
        return 'bg-surface-sunken text-muted ring-border';
    };
    const eventBadgeClass = (event) => {
        if (event === 'created' || event === 'member_added') return 'bg-ok/10 text-ok ring-ok/30';
        if (event === 'deleted' || event === 'member_removed') return 'bg-danger/10 text-danger ring-danger/30';
        if (event === 'restored') return 'bg-brand-soft text-brand ring-brand/30';
        if (event === 'updated') return 'bg-brand-soft text-brand ring-brand-soft-line';
        return 'bg-surface-sunken text-muted ring-border';
    };
    const sourceLabel = (source) => ({ api: 'API', ci_cd: 'CI/CD', web: 'Web', system: 'System' }[source]
        ?? source.replaceAll('_', ' ').replace(/^\w/, (letter) => letter.toUpperCase()));
    const initials = (name) => (name ?? 'System')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
    const auditUrl = () => `/accounts/${navigationTeam.slug}/audit`;
    const visitFilters = () => router.get(auditUrl(), {
        apps: selectedApps.length ? selectedApps : undefined,
        actors: selectedActors.length ? selectedActors : undefined,
        events: selectedEvents.length ? selectedEvents : undefined,
        sources: selectedSources.length ? selectedSources : undefined,
        from: selectedFrom || undefined,
        to: selectedTo || undefined,
    }, {
        only: ['audits', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
    const clearFilters = () => {
        selectedApps = [];
        selectedActors = [];
        selectedEvents = [];
        selectedSources = [];
        selectedFrom = '';
        selectedTo = '';
        router.get(auditUrl(), {}, {
            only: ['audits', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
</script>

<svelte:head><title>Audit log · App Manager</title></svelte:head>

<WorkspacePage {auth} {navigationTeams} {navigationTeam} active="audit" eyebrow="Workspace security" title="Audit log" {flash}>
        <div class="mb-4 border-y border-border-subtle bg-surface-sunken/40 px-4 py-3.5">
            <div class="grid gap-2.5 md:grid-cols-2 xl:grid-cols-4">
                <MultiSelectFilter label="Applications" options={appOptions} values={selectedApps} searchable searchPlaceholder="Search applications…" onChange={(values) => { selectedApps = values; visitFilters(); }} />
                <MultiSelectFilter label="Actors" options={actorOptions} values={selectedActors} searchable searchPlaceholder="Search actors…" onChange={(values) => { selectedActors = values; visitFilters(); }} />
                <MultiSelectFilter label="Events" options={eventOptions} values={selectedEvents} onChange={(values) => { selectedEvents = values; visitFilters(); }} />
                <MultiSelectFilter label="Sources" options={sourceOptions} values={selectedSources} onChange={(values) => { selectedSources = values; visitFilters(); }} />
            </div>
            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label class="w-40"><span class="label">From</span><input type="date" bind:value={selectedFrom} on:change={visitFilters} /></label>
                <label class="w-40"><span class="label">To</span><input type="date" min={selectedFrom || undefined} bind:value={selectedTo} on:change={visitFilters} /></label>
            </div>
            {#if hasFilters}
                <div class="mt-3">
                    <button type="button" class="ghost px-3 py-2 text-xs" on:click={clearFilters}>Clear filters</button>
                </div>
            {/if}
        </div>

        <section class="card overflow-clip p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <h2 class="text-xs font-bold text-text">
                    Team activity
                    <span class="ml-1 font-mono text-[11px] font-medium text-faint tabular-nums">{audits.total ?? 0}{hasFilters ? ' matching' : ''} in {navigationTeam?.name}</span>
                </h2>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-ok/10 px-2.5 py-1 text-[10px] font-semibold text-ok">
                    <ShieldCheck class="h-3.5 w-3.5" strokeWidth={1.8} />
                    Recorded automatically
                </span>
            </div>

            {#if audits.data?.length}
                <div class="sticky top-0 z-10 hidden grid-cols-[minmax(0,1fr)_150px_120px_140px] items-center gap-4 border-y border-border-subtle bg-surface-sunken px-5 py-2 text-[10px] font-bold tracking-[0.1em] text-faint uppercase backdrop-blur lg:grid">
                    <span>Activity</span>
                    <span>Actor</span>
                    <span>Source</span>
                    <span>When</span>
                </div>

                <div class="divide-y divide-border-subtle">
                    {#each audits.data as entry}
                        <article class="group grid gap-4 px-5 py-3.5 transition hover:bg-surface-sunken sm:grid-cols-3 lg:grid-cols-[minmax(0,1fr)_150px_120px_140px] lg:items-start">
                            <div class="flex min-w-0 items-start gap-3 sm:col-span-3 lg:col-span-1">
                                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg ring-1 ring-inset {eventMarkClass(entry.event)}">
                                    <svelte:component this={eventIcon(entry.event)} class="h-4 w-4" strokeWidth={1.9} />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                        {#if entry.target?.href}
                                            <Link href={entry.target.href} class="inline-flex min-w-0 items-center gap-1 text-[13px] font-semibold text-text hover:text-brand">
                                                <span class="truncate">{entry.target.label}</span>
                                                <ArrowUpRight class="h-3.5 w-3.5 shrink-0" strokeWidth={1.8} />
                                            </Link>
                                        {:else}
                                            <p class="truncate text-[13px] font-semibold text-text">{entry.target?.label ?? `${entry.auditable_type} #${entry.auditable_id}`}</p>
                                        {/if}
                                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset {eventBadgeClass(entry.event)}">{entry.action_label ?? eventLabel(entry.event)}</span>
                                    </div>

                                    <p class="mt-0.5 truncate text-[11px] text-faint">
                                        {entry.target?.type ?? entry.auditable_type}{#if entry.target?.meta} · {entry.target.meta}{/if}
                                    </p>

                                    {#if entry.changes?.length}
                                        <div class="mt-1.5">
                                            {#if entry.changes[0].key === 'tags'}
                                                <AuditTagChange change={entry.changes[0]} />
                                            {:else}
                                                <p class="text-[11px] leading-4 text-muted">
                                                    <span class="font-semibold text-muted">{entry.changes[0].field}:</span>
                                                    {formatChange(entry.changes[0])}
                                                </p>
                                            {/if}
                                        </div>
                                        {#if entry.changes.length > 1}
                                            <details class="group/details mt-1.5">
                                                <summary class="cursor-pointer list-none text-[11px] font-semibold text-brand hover:text-brand">
                                                    Show {entry.changes.length - 1} more {entry.changes.length === 2 ? 'change' : 'changes'}
                                                </summary>
                                                <div class="mt-2 space-y-1.5 border-l-2 border-brand-soft-line pl-3">
                                                    {#each entry.changes.slice(1) as change}
                                                        {#if change.key === 'tags'}
                                                            <AuditTagChange {change} />
                                                        {:else}
                                                            <p class="text-[11px] leading-4 text-muted"><span class="font-semibold text-muted">{change.field}:</span> {formatChange(change)}</p>
                                                        {/if}
                                                    {/each}
                                                </div>
                                            </details>
                                        {/if}
                                    {:else if entry.action_description}
                                        <p class="mt-1.5 text-[11px] leading-4 text-muted">{entry.action_description}</p>
                                    {/if}
                                </div>
                            </div>

                            <div class="min-w-0 lg:pt-0.5">
                                <p class="mb-1.5 text-[9px] font-bold tracking-[0.1em] text-faint uppercase lg:hidden">Actor</p>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-surface-sunken text-[9px] font-bold text-muted">{initials(entry.actor?.name)}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-semibold text-text">{entry.actor?.name ?? 'System'}</p>
                                        {#if entry.actor?.detail}<p class="truncate text-[10px] text-faint">{entry.actor.detail}</p>{/if}
                                    </div>
                                </div>
                            </div>

                            <div class="min-w-0 lg:pt-0.5">
                                <p class="mb-1.5 text-[9px] font-bold tracking-[0.1em] text-faint uppercase lg:hidden">Source</p>
                                <span class="inline-flex max-w-full truncate rounded-md bg-surface-sunken px-2 py-1 text-[10px] font-semibold text-muted">{entry.source_label ?? entry.source ?? 'Unknown'}</span>
                                {#if entry.api_key}
                                    <p class="mt-1 truncate text-[10px] text-muted">
                                        {#if entry.api_key.href}<Link href={entry.api_key.href} class="font-medium text-brand hover:text-brand">{entry.api_key.name}</Link>{:else}{entry.api_key.name}{/if}
                                    </p>
                                {/if}
                                {#if entry.ip_address}<p class="mt-1 truncate font-mono text-[10px] text-faint" title={entry.user_agent ?? undefined}>{entry.ip_address}</p>{/if}
                            </div>

                            <time class="font-mono text-[10px] text-faint tabular-nums lg:pt-1" datetime={entry.created_at} title={formatFullDate(entry.created_at)}>
                                <span class="mb-1.5 block font-sans text-[9px] font-bold tracking-[0.1em] text-faint uppercase lg:hidden">When</span>
                                <span class="block font-sans text-[11px] font-medium text-muted">{formatDay(entry.created_at)}</span>
                                <span class="mt-0.5 block">{formatTime(entry.created_at)}</span>
                            </time>
                        </article>
                    {/each}
                </div>

                {#if audits.last_page > 1}
                    {#key paginationKey}
                        <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle px-5 py-4" aria-label="Audit pagination">
                            <p class="font-mono text-[11px] text-faint tabular-nums">{audits.from}–{audits.to} of {audits.total}</p>
                            <div class="flex items-center gap-1.5">
                                {#if audits.prev_page_url}<Link href={audits.prev_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}
                                {#each pageLinks as link}
                                    {#if link.url}<Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center font-mono text-xs tabular-nums {link.active ? 'border-brand-soft-line bg-brand-soft text-brand' : ''}" preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>{:else}<span class="px-1 text-xs text-faint">{link.label}</span>{/if}
                                {/each}
                                {#if audits.next_page_url}<Link href={audits.next_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>{/if}
                            </div>
                        </nav>
                    {/key}
                {/if}
            {:else}
                <div class="px-6 py-16 text-center">
                    <span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-md bg-brand-soft text-brand"><ScrollText class="h-6 w-6" strokeWidth={1.7} /></span>
                    <h3 class="text-sm font-bold">{hasFilters ? 'No matching activity' : 'No activity recorded yet'}</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm text-muted">{hasFilters ? 'Try a different combination or clear the filters.' : 'Changes to apps, builds, credentials, and integrations will show up here.'}</p>
                    {#if hasFilters}<button type="button" class="secondary mt-5" on:click={clearFilters}>Clear filters</button>{/if}
                </div>
            {/if}
        </section>
</WorkspacePage>
