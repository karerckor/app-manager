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
    import TabSelector from '../../Components/TabSelector.svelte';

    export let auth;
    export let flash = {};
    export let navigationTeams = [];
    export let navigationTeam = null;
    export let audits = {};
    export let filters = {};
    export let filterOptions = { apps: [], users: [], events: [] };

    let selectedApp = filters.app ?? '';
    let selectedUser = filters.user ?? '';
    let selectedEvent = filters.event ?? '';

    $: hasFilters = Boolean(selectedApp || selectedUser || selectedEvent);
    $: pageLinks = (audits.links ?? []).slice(1, -1);
    $: paginationKey = (audits.links ?? [])
        .map((link) => link.url ?? link.label)
        .join('|');
    $: eventChips = buildEventChips();
    $: eventTabItems = eventChips.map((chip) => ({
        value: chip.value,
        label: chip.label,
        tone: eventTone(chip.value),
        count: chip.value === '' && !hasFilters ? (audits.total ?? 0) : undefined,
    }));

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
        if (event === 'created' || event === 'member_added') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
        if (event === 'deleted' || event === 'member_removed') return 'bg-rose-50 text-rose-700 ring-rose-200';
        if (event === 'restored') return 'bg-sky-50 text-sky-700 ring-sky-200';
        if (event === 'updated') return 'bg-indigo-50 text-indigo-700 ring-indigo-200';
        return 'bg-slate-100 text-slate-600 ring-slate-200';
    };
    const eventBadgeClass = (event) => {
        if (event === 'created' || event === 'member_added') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
        if (event === 'deleted' || event === 'member_removed') return 'bg-rose-50 text-rose-700 ring-rose-200';
        if (event === 'restored') return 'bg-sky-50 text-sky-700 ring-sky-200';
        if (event === 'updated') return 'bg-indigo-50 text-indigo-700 ring-indigo-200';
        return 'bg-slate-50 text-slate-600 ring-slate-200';
    };
    const eventTone = (event) => ({
        created: 'emerald',
        deleted: 'rose',
    }[event] ?? 'indigo');
    const initials = (name) => (name ?? 'System')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
    const buildEventChips = () => {
        const available = filterOptions.events ?? [];
        const values = ['', ...['created', 'updated', 'deleted'].filter((event) => available.includes(event))];
        if (selectedEvent && !values.includes(selectedEvent)) values.push(selectedEvent);
        return values.map((value) => ({ value, label: eventLabel(value) }));
    };
    const auditUrl = () => `/accounts/${navigationTeam.slug}/audit`;
    const visitFilters = (event = selectedEvent) => router.get(auditUrl(), {
        app: selectedApp || undefined,
        user: selectedUser || undefined,
        event: event || undefined,
    }, {
        only: ['audits', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
    const applyEvent = (event) => {
        selectedEvent = event;
        visitFilters(event);
    };
    const clearFilters = () => {
        selectedApp = '';
        selectedUser = '';
        selectedEvent = '';
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
        <div class="mb-4">
            <TabSelector variant="pills" ariaLabel="Quick event filters" value={selectedEvent} items={eventTabItems} onChange={applyEvent} />
        </div>

        <div class="mb-4 grid gap-2 md:grid-cols-[minmax(240px,1fr)_190px_170px_auto]">
            <label>
                <span class="sr-only">Application</span>
                <select class="py-2" bind:value={selectedApp} on:change={() => visitFilters()}>
                    <option value="">All applications</option>
                    {#each filterOptions.apps ?? [] as app}<option value={app.id}>{app.name}</option>{/each}
                </select>
            </label>
            <label>
                <span class="sr-only">Actor</span>
                <select class="py-2" bind:value={selectedUser} on:change={() => visitFilters()}>
                    <option value="">All actors</option>
                    {#each filterOptions.users ?? [] as user}<option value={user.id}>{user.name}</option>{/each}
                </select>
            </label>
            <label>
                <span class="sr-only">Event</span>
                <select class="py-2" bind:value={selectedEvent} on:change={() => visitFilters()}>
                    <option value="">All events</option>
                    {#each filterOptions.events ?? [] as event}<option value={event}>{eventLabel(event)}</option>{/each}
                </select>
            </label>
            {#if hasFilters}
                <button type="button" class="ghost px-3 py-2 text-xs" on:click={clearFilters}>Clear</button>
            {/if}
        </div>

        <section class="card overflow-clip p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <h2 class="text-xs font-bold text-slate-900">
                    Team activity
                    <span class="ml-1 font-mono text-[11px] font-medium text-slate-400 tabular-nums">{audits.total ?? 0}{hasFilters ? ' matching' : ''} in {navigationTeam?.name}</span>
                </h2>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">
                    <ShieldCheck class="h-3.5 w-3.5" strokeWidth={1.8} />
                    Recorded automatically
                </span>
            </div>

            {#if audits.data?.length}
                <div class="sticky top-0 z-10 hidden grid-cols-[minmax(0,1fr)_150px_120px_140px] items-center gap-4 border-y border-slate-100 bg-slate-50/80 px-5 py-2 text-[10px] font-bold tracking-[0.1em] text-slate-400 uppercase backdrop-blur lg:grid">
                    <span>Activity</span>
                    <span>Actor</span>
                    <span>Source</span>
                    <span>When</span>
                </div>

                <div class="divide-y divide-slate-100">
                    {#each audits.data as entry}
                        <article class="group grid gap-4 px-5 py-3.5 transition hover:bg-slate-50/60 sm:grid-cols-3 lg:grid-cols-[minmax(0,1fr)_150px_120px_140px] lg:items-start">
                            <div class="flex min-w-0 items-start gap-3 sm:col-span-3 lg:col-span-1">
                                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg ring-1 ring-inset {eventMarkClass(entry.event)}">
                                    <svelte:component this={eventIcon(entry.event)} class="h-4 w-4" strokeWidth={1.9} />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                        {#if entry.target?.href}
                                            <Link href={entry.target.href} class="inline-flex min-w-0 items-center gap-1 font-semibold text-slate-900 hover:text-indigo-700">
                                                <span class="truncate">{entry.target.label}</span>
                                                <ArrowUpRight class="h-3.5 w-3.5 shrink-0" strokeWidth={1.8} />
                                            </Link>
                                        {:else}
                                            <p class="truncate font-semibold text-slate-900">{entry.target?.label ?? `${entry.auditable_type} #${entry.auditable_id}`}</p>
                                        {/if}
                                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset {eventBadgeClass(entry.event)}">{entry.action_label ?? eventLabel(entry.event)}</span>
                                    </div>

                                    <p class="mt-0.5 truncate text-[11px] text-slate-400">
                                        {entry.target?.type ?? entry.auditable_type}{#if entry.target?.meta} · {entry.target.meta}{/if}
                                    </p>

                                    {#if entry.changes?.length}
                                        <div class="mt-1.5">
                                            {#if entry.changes[0].key === 'tags'}
                                                <AuditTagChange change={entry.changes[0]} />
                                            {:else}
                                                <p class="text-[11px] leading-4 text-slate-500">
                                                    <span class="font-semibold text-slate-600">{entry.changes[0].field}:</span>
                                                    {formatChange(entry.changes[0])}
                                                </p>
                                            {/if}
                                        </div>
                                        {#if entry.changes.length > 1}
                                            <details class="group/details mt-1.5">
                                                <summary class="cursor-pointer list-none text-[11px] font-semibold text-indigo-600 hover:text-indigo-700">
                                                    Show {entry.changes.length - 1} more {entry.changes.length === 2 ? 'change' : 'changes'}
                                                </summary>
                                                <div class="mt-2 space-y-1.5 border-l-2 border-indigo-100 pl-3">
                                                    {#each entry.changes.slice(1) as change}
                                                        {#if change.key === 'tags'}
                                                            <AuditTagChange {change} />
                                                        {:else}
                                                            <p class="text-[11px] leading-4 text-slate-500"><span class="font-semibold text-slate-600">{change.field}:</span> {formatChange(change)}</p>
                                                        {/if}
                                                    {/each}
                                                </div>
                                            </details>
                                        {/if}
                                    {:else if entry.action_description}
                                        <p class="mt-1.5 text-[11px] leading-4 text-slate-500">{entry.action_description}</p>
                                    {/if}
                                </div>
                            </div>

                            <div class="min-w-0 lg:pt-0.5">
                                <p class="mb-1.5 text-[9px] font-bold tracking-[0.1em] text-slate-400 uppercase lg:hidden">Actor</p>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-slate-100 text-[9px] font-bold text-slate-600">{initials(entry.actor?.name)}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-semibold text-slate-700">{entry.actor?.name ?? 'System'}</p>
                                        {#if entry.actor?.detail}<p class="truncate text-[10px] text-slate-400">{entry.actor.detail}</p>{/if}
                                    </div>
                                </div>
                            </div>

                            <div class="min-w-0 lg:pt-0.5">
                                <p class="mb-1.5 text-[9px] font-bold tracking-[0.1em] text-slate-400 uppercase lg:hidden">Source</p>
                                <span class="inline-flex max-w-full truncate rounded-md bg-slate-50 px-2 py-1 text-[10px] font-semibold text-slate-600">{entry.source_label ?? entry.source ?? 'Unknown'}</span>
                                {#if entry.api_key}
                                    <p class="mt-1 truncate text-[10px] text-slate-500">
                                        {#if entry.api_key.href}<Link href={entry.api_key.href} class="font-medium text-indigo-600 hover:text-indigo-700">{entry.api_key.name}</Link>{:else}{entry.api_key.name}{/if}
                                    </p>
                                {/if}
                                {#if entry.ip_address}<p class="mt-1 truncate font-mono text-[10px] text-slate-400" title={entry.user_agent ?? undefined}>{entry.ip_address}</p>{/if}
                            </div>

                            <time class="font-mono text-[10px] text-slate-400 tabular-nums lg:pt-1" datetime={entry.created_at} title={formatFullDate(entry.created_at)}>
                                <span class="mb-1.5 block font-sans text-[9px] font-bold tracking-[0.1em] text-slate-400 uppercase lg:hidden">When</span>
                                <span class="block font-sans text-[11px] font-medium text-slate-600">{formatDay(entry.created_at)}</span>
                                <span class="mt-0.5 block">{formatTime(entry.created_at)}</span>
                            </time>
                        </article>
                    {/each}
                </div>

                {#if audits.last_page > 1}
                    {#key paginationKey}
                        <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4" aria-label="Audit pagination">
                            <p class="font-mono text-[11px] text-slate-400 tabular-nums">{audits.from}–{audits.to} of {audits.total}</p>
                            <div class="flex items-center gap-1.5">
                                {#if audits.prev_page_url}<Link href={audits.prev_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Previous</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Previous</span>{/if}
                                {#each pageLinks as link}
                                    {#if link.url}<Link href={link.url} class="secondary min-w-9 px-3 py-2 text-center font-mono text-xs tabular-nums {link.active ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : ''}" preserveScroll aria-current={link.active ? 'page' : undefined}>{link.label}</Link>{:else}<span class="px-1 text-xs text-slate-400">{link.label}</span>{/if}
                                {/each}
                                {#if audits.next_page_url}<Link href={audits.next_page_url} class="secondary px-3 py-2 text-xs" preserveScroll>Next</Link>{:else}<span class="secondary pointer-events-none px-3 py-2 text-xs opacity-40">Next</span>{/if}
                            </div>
                        </nav>
                    {/key}
                {/if}
            {:else}
                <div class="px-6 py-16 text-center">
                    <span class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><ScrollText class="h-6 w-6" strokeWidth={1.7} /></span>
                    <h3 class="text-sm font-bold">{hasFilters ? 'No matching activity' : 'No activity recorded yet'}</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">{hasFilters ? 'Try a different combination or clear the filters.' : 'Changes to apps, builds, credentials, and integrations will show up here.'}</p>
                    {#if hasFilters}<button type="button" class="secondary mt-5" on:click={clearFilters}>Clear filters</button>{/if}
                </div>
            {/if}
        </section>
</WorkspacePage>
